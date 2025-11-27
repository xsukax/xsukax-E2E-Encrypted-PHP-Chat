<?php
error_reporting(0);
ini_set('display_errors', 0);
session_start();

define('DB_FILE', 'chats.db');
define('CHAT_LIFETIME', 24 * 60 * 60);
define('HEARTBEAT_TIMEOUT', 30);
define('MAX_PARTICIPANTS', 2);

function initDB() {
    try {
        $db = new SQLite3(DB_FILE);
        $db->busyTimeout(5000);
        
        $db->exec('CREATE TABLE IF NOT EXISTS chats (
            id TEXT PRIMARY KEY,
            password_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )');
        
        $db->exec('CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            encrypted_content TEXT NOT NULL,
            timestamp INTEGER NOT NULL,
            edited INTEGER DEFAULT 0,
            deleted INTEGER DEFAULT 0,
            version INTEGER DEFAULT 1,
            msg_type TEXT DEFAULT "message"
        )');
        
        $db->exec('CREATE TABLE IF NOT EXISTS participants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            encrypted_name TEXT NOT NULL,
            last_seen INTEGER NOT NULL,
            UNIQUE(chat_id, user_id)
        )');
        
        $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_chat ON messages(chat_id, id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_participants_chat ON participants(chat_id)');
        
        return $db;
    } catch (Exception $e) {
        return null;
    }
}

function cleanupOldChats($db) {
    try {
        $expiry = time() - CHAT_LIFETIME;
        $stmt = $db->prepare('SELECT id FROM chats WHERE created_at < :expiry');
        $stmt->bindValue(':expiry', $expiry, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $chatId = $row['id'];
            $db->exec("DELETE FROM messages WHERE chat_id = '" . SQLite3::escapeString($chatId) . "'");
            $db->exec("DELETE FROM participants WHERE chat_id = '" . SQLite3::escapeString($chatId) . "'");
            $db->exec("DELETE FROM chats WHERE id = '" . SQLite3::escapeString($chatId) . "'");
        }
    } catch (Exception $e) {}
}

function cleanupInactiveParticipants($db, $chatId) {
    $timeout = time() - HEARTBEAT_TIMEOUT;
    $stmt = $db->prepare('SELECT user_id, encrypted_name FROM participants WHERE chat_id = :chat_id AND last_seen < :timeout');
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $stmt->bindValue(':timeout', $timeout, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $removed = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $removed[] = ['user_id' => $row['user_id'], 'encrypted_name' => $row['encrypted_name']];
    }
    
    $stmt = $db->prepare('DELETE FROM participants WHERE chat_id = :chat_id AND last_seen < :timeout');
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $stmt->bindValue(':timeout', $timeout, SQLITE3_INTEGER);
    $stmt->execute();
    
    return $removed;
}

function getActiveParticipantCount($db, $chatId) {
    cleanupInactiveParticipants($db, $chatId);
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM participants WHERE chat_id = :chat_id');
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return (int)$row['cnt'];
}

function isUserInRoom($db, $chatId, $userId) {
    $stmt = $db->prepare('SELECT id FROM participants WHERE chat_id = :chat_id AND user_id = :user_id');
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $stmt->bindValue(':user_id', $userId, SQLITE3_TEXT);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC) !== false;
}

function addSystemMessage($db, $chatId, $type, $encryptedContent) {
    $stmt = $db->prepare('INSERT INTO messages (chat_id, user_id, encrypted_content, timestamp, msg_type) VALUES (:chat_id, :user_id, :content, :time, :type)');
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $stmt->bindValue(':user_id', 'SYSTEM', SQLITE3_TEXT);
    $stmt->bindValue(':content', $encryptedContent, SQLITE3_TEXT);
    $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    $stmt->execute();
    return $db->lastInsertRowID();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    try {
        if (ob_get_level()) ob_clean();
        
        $db = initDB();
        if (!$db) throw new Exception('Database initialization failed');
        
        cleanupOldChats($db);
        
        if ($action === 'create_chat') {
            $passwordHash = $_POST['password_hash'] ?? '';
            if (empty($passwordHash)) throw new Exception('Password required');
            
            $chatId = bin2hex(random_bytes(16));
            $stmt = $db->prepare('INSERT INTO chats (id, password_hash, created_at) VALUES (:id, :hash, :time)');
            $stmt->bindValue(':id', $chatId, SQLITE3_TEXT);
            $stmt->bindValue(':hash', password_hash($passwordHash, PASSWORD_ARGON2ID), SQLITE3_TEXT);
            $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
            $stmt->execute();
            
            echo json_encode(['success' => true, 'chat_id' => $chatId]);
            
        } elseif ($action === 'verify_chat') {
            $chatId = $_POST['chat_id'] ?? '';
            $userId = $_POST['user_id'] ?? '';
            
            $stmt = $db->prepare('SELECT password_hash, created_at FROM chats WHERE id = :id');
            $stmt->bindValue(':id', $chatId, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Chat not found']);
            } elseif (password_verify($_POST['password_hash'] ?? '', $row['password_hash'])) {
                $currentCount = getActiveParticipantCount($db, $chatId);
                $isAlreadyIn = isUserInRoom($db, $chatId, $userId);
                
                if (!$isAlreadyIn && $currentCount >= MAX_PARTICIPANTS) {
                    echo json_encode(['success' => false, 'error' => 'Room is full (max 2 users)']);
                } else {
                    echo json_encode([
                        'success' => true, 
                        'remaining_time' => CHAT_LIFETIME - (time() - $row['created_at']),
                        'participant_count' => $currentCount,
                        'is_already_in' => $isAlreadyIn
                    ]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid password']);
            }
            
        } elseif ($action === 'join_room') {
            $chatId = $_POST['chat_id'] ?? '';
            $userId = $_POST['user_id'] ?? '';
            $encryptedName = $_POST['encrypted_name'] ?? '';
            
            if (empty($chatId) || empty($userId)) throw new Exception('Missing parameters');
            
            $removed = cleanupInactiveParticipants($db, $chatId);
            $isAlreadyIn = isUserInRoom($db, $chatId, $userId);
            $currentCount = getActiveParticipantCount($db, $chatId);
            
            if (!$isAlreadyIn && $currentCount >= MAX_PARTICIPANTS) {
                echo json_encode(['success' => false, 'error' => 'Room is full']);
            } else {
                $stmt = $db->prepare('INSERT OR REPLACE INTO participants (chat_id, user_id, encrypted_name, last_seen) VALUES (:chat_id, :user_id, :name, :time)');
                $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
                $stmt->bindValue(':user_id', $userId, SQLITE3_TEXT);
                $stmt->bindValue(':name', $encryptedName, SQLITE3_TEXT);
                $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
                $stmt->execute();
                
                if (!$isAlreadyIn) {
                    $encryptedNotif = $_POST['encrypted_join_msg'] ?? '';
                    if ($encryptedNotif) {
                        addSystemMessage($db, $chatId, 'join', $encryptedNotif);
                    }
                }
                
                $stmt = $db->prepare('SELECT user_id, encrypted_name FROM participants WHERE chat_id = :chat_id');
                $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
                $result = $stmt->execute();
                $participants = [];
                while ($p = $result->fetchArray(SQLITE3_ASSOC)) {
                    $participants[] = $p;
                }
                
                echo json_encode([
                    'success' => true, 
                    'participants' => $participants,
                    'removed_users' => $removed
                ]);
            }
            
        } elseif ($action === 'leave_room') {
            $chatId = $_POST['chat_id'] ?? '';
            $userId = $_POST['user_id'] ?? '';
            $encryptedLeaveMsg = $_POST['encrypted_leave_msg'] ?? '';
            
            $stmt = $db->prepare('DELETE FROM participants WHERE chat_id = :chat_id AND user_id = :user_id');
            $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $userId, SQLITE3_TEXT);
            $stmt->execute();
            
            if ($encryptedLeaveMsg) {
                addSystemMessage($db, $chatId, 'leave', $encryptedLeaveMsg);
            }
            
            echo json_encode(['success' => true]);
            
        } elseif ($action === 'heartbeat') {
            $chatId = $_POST['chat_id'] ?? '';
            $userId = $_POST['user_id'] ?? '';
            $encryptedName = $_POST['encrypted_name'] ?? '';
            
            $removed = cleanupInactiveParticipants($db, $chatId);
            
            foreach ($removed as $r) {
                if ($r['user_id'] !== $userId && !empty($r['encrypted_name'])) {
                    $leaveData = json_encode(['type' => 'timeout_leave', 'encrypted_name' => $r['encrypted_name']]);
                    addSystemMessage($db, $chatId, 'leave', $leaveData);
                }
            }
            
            $stmt = $db->prepare('UPDATE participants SET last_seen = :time, encrypted_name = :name WHERE chat_id = :chat_id AND user_id = :user_id');
            $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
            $stmt->bindValue(':name', $encryptedName, SQLITE3_TEXT);
            $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $userId, SQLITE3_TEXT);
            $stmt->execute();
            
            $stmt = $db->prepare('SELECT user_id, encrypted_name FROM participants WHERE chat_id = :chat_id');
            $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
            $result = $stmt->execute();
            $participants = [];
            while ($p = $result->fetchArray(SQLITE3_ASSOC)) {
                $participants[] = $p;
            }
            
            echo json_encode(['success' => true, 'participants' => $participants, 'removed' => $removed]);
            
        } elseif ($action === 'update_nickname') {
            $chatId = $_POST['chat_id'] ?? '';
            $userId = $_POST['user_id'] ?? '';
            $encryptedName = $_POST['encrypted_name'] ?? '';
            $encryptedRenameMsg = $_POST['encrypted_rename_msg'] ?? '';
            
            $stmt = $db->prepare('UPDATE participants SET encrypted_name = :name WHERE chat_id = :chat_id AND user_id = :user_id');
            $stmt->bindValue(':name', $encryptedName, SQLITE3_TEXT);
            $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $userId, SQLITE3_TEXT);
            $stmt->execute();
            
            if ($encryptedRenameMsg) {
                addSystemMessage($db, $chatId, 'rename', $encryptedRenameMsg);
            }
            
            echo json_encode(['success' => true]);
            
        } elseif ($action === 'send_message') {
            $stmt = $db->prepare('INSERT INTO messages (chat_id, user_id, encrypted_content, timestamp, edited, msg_type) VALUES (:chat_id, :user_id, :content, :time, 0, :type)');
            $stmt->bindValue(':chat_id', $_POST['chat_id'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $_POST['user_id'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':content', $_POST['encrypted_content'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
            $stmt->bindValue(':type', 'message', SQLITE3_TEXT);
            $stmt->execute();
            echo json_encode(['success' => true, 'message_id' => $db->lastInsertRowID()]);
            
        } elseif ($action === 'edit_message') {
            $msgId = intval($_POST['message_id'] ?? 0);
            $userId = $_POST['user_id'] ?? '';
            
            $stmt = $db->prepare('SELECT version FROM messages WHERE id = :id AND user_id = :user_id');
            $stmt->bindValue(':id', $msgId, SQLITE3_INTEGER);
            $stmt->bindValue(':user_id', $userId, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            
            if ($row) {
                $newVersion = (int)$row['version'] + 1;
                $stmt = $db->prepare('UPDATE messages SET encrypted_content = :content, edited = 1, version = :version WHERE id = :id AND user_id = :user_id');
                $stmt->bindValue(':content', $_POST['encrypted_content'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':version', $newVersion, SQLITE3_INTEGER);
                $stmt->bindValue(':id', $msgId, SQLITE3_INTEGER);
                $stmt->bindValue(':user_id', $userId, SQLITE3_TEXT);
                $stmt->execute();
                echo json_encode(['success' => $db->changes() > 0, 'version' => $newVersion]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Message not found']);
            }
            
        } elseif ($action === 'delete_message') {
            $msgId = intval($_POST['message_id'] ?? 0);
            $userId = $_POST['user_id'] ?? '';
            
            $stmt = $db->prepare('SELECT version FROM messages WHERE id = :id AND user_id = :user_id');
            $stmt->bindValue(':id', $msgId, SQLITE3_INTEGER);
            $stmt->bindValue(':user_id', $userId, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            
            if ($row) {
                $newVersion = (int)$row['version'] + 1;
                $stmt = $db->prepare('UPDATE messages SET deleted = 1, version = :version WHERE id = :id AND user_id = :user_id');
                $stmt->bindValue(':version', $newVersion, SQLITE3_INTEGER);
                $stmt->bindValue(':id', $msgId, SQLITE3_INTEGER);
                $stmt->bindValue(':user_id', $userId, SQLITE3_TEXT);
                $stmt->execute();
                echo json_encode(['success' => $db->changes() > 0, 'version' => $newVersion]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Message not found']);
            }
            
        } elseif ($action === 'get_messages') {
            $chatId = $_POST['chat_id'] ?? '';
            $lastId = intval($_POST['last_id'] ?? 0);
            $knownVersions = json_decode($_POST['known_versions'] ?? '{}', true) ?: [];
            
            $stmt = $db->prepare('SELECT id, user_id, encrypted_content, timestamp, edited, deleted, version, msg_type FROM messages WHERE chat_id = :chat_id AND id > :last_id ORDER BY id ASC LIMIT 100');
            $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
            $stmt->bindValue(':last_id', $lastId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            $messages = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $messages[] = [
                    'id' => (int)$row['id'],
                    'user_id' => $row['user_id'],
                    'encrypted_content' => $row['encrypted_content'],
                    'timestamp' => (int)$row['timestamp'],
                    'edited' => (int)$row['edited'],
                    'deleted' => (int)$row['deleted'],
                    'version' => (int)$row['version'],
                    'msg_type' => $row['msg_type']
                ];
            }
            
            $updates = [];
            if (!empty($knownVersions)) {
                $ids = array_keys($knownVersions);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare("SELECT id, user_id, encrypted_content, timestamp, edited, deleted, version, msg_type FROM messages WHERE chat_id = ? AND id IN ($placeholders)");
                $stmt->bindValue(1, $chatId, SQLITE3_TEXT);
                foreach ($ids as $i => $id) {
                    $stmt->bindValue($i + 2, (int)$id, SQLITE3_INTEGER);
                }
                $result = $stmt->execute();
                
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $msgId = (string)$row['id'];
                    if (isset($knownVersions[$msgId]) && (int)$row['version'] > (int)$knownVersions[$msgId]) {
                        $updates[] = [
                            'id' => (int)$row['id'],
                            'user_id' => $row['user_id'],
                            'encrypted_content' => $row['encrypted_content'],
                            'timestamp' => (int)$row['timestamp'],
                            'edited' => (int)$row['edited'],
                            'deleted' => (int)$row['deleted'],
                            'version' => (int)$row['version'],
                            'msg_type' => $row['msg_type']
                        ];
                    }
                }
            }
            
            echo json_encode(['success' => true, 'messages' => $messages, 'updates' => $updates]);
            
        } elseif ($action === 'destroy_chat') {
            $chatId = $_POST['chat_id'] ?? '';
            if (empty($chatId)) throw new Exception('Chat ID required');
            
            $stmt = $db->prepare('SELECT id FROM chats WHERE id = :id');
            $stmt->bindValue(':id', $chatId, SQLITE3_TEXT);
            $result = $stmt->execute();
            
            if ($result->fetchArray(SQLITE3_ASSOC)) {
                $db->exec("DELETE FROM messages WHERE chat_id = '" . SQLite3::escapeString($chatId) . "'");
                $db->exec("DELETE FROM participants WHERE chat_id = '" . SQLite3::escapeString($chatId) . "'");
                $db->exec("DELETE FROM chats WHERE id = '" . SQLite3::escapeString($chatId) . "'");
                echo json_encode(['success' => true]);
            } else {
                throw new Exception('Chat not found');
            }
            
        } else {
            throw new Exception('Invalid action');
        }
        
        if ($db) $db->close();
        ob_end_flush();
        
    } catch (Exception $e) {
        if (ob_get_level()) ob_end_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>xsukax E2E Encrypted PHP Chat</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --safe-bottom: env(safe-area-inset-bottom, 0px); }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Helvetica', sans-serif; background: #fafbfc; color: #24292f; height: 100vh; overflow: hidden; }
        .header { background: #ffffff; border-bottom: 1px solid #e1e4e8; padding: 0.875rem 1rem; text-align: center; box-shadow: 0 1px 0 rgba(27,31,35,0.04); }
        .logo { font-size: 1.25rem; font-weight: 600; color: #0969da; margin-bottom: 0.125rem; }
        .tagline { font-size: 0.7rem; color: #57606a; }
        .centered-form { max-width: 420px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: #ffffff; border: 1px solid #e1e4e8; border-radius: 6px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(27,31,35,0.05); }
        .input { width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #d0d7de; border-radius: 6px; background: #ffffff; color: #24292f; font-size: 0.875rem; font-family: inherit; transition: border-color 0.2s; }
        .input:focus { outline: none; border-color: #0969da; box-shadow: 0 0 0 3px rgba(9,105,218,0.12); }
        .btn { padding: 0.625rem 1rem; border: 1px solid rgba(27,31,35,0.15); border-radius: 6px; background: #f6f8fa; color: #24292f; font-weight: 500; cursor: pointer; font-size: 0.875rem; transition: all 0.15s; display: inline-flex; align-items: center; justify-content: center; gap: 0.375rem; font-family: inherit; white-space: nowrap; }
        .btn:hover { background: #f3f4f6; border-color: rgba(27,31,35,0.2); }
        .btn:active { background: #edeff1; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background: #2da44e; border-color: rgba(27,31,35,0.15); color: #ffffff; }
        .btn-primary:hover { background: #2c974b; }
        .btn-danger { background: #cf222e; border-color: rgba(27,31,35,0.15); color: #ffffff; }
        .btn-danger:hover { background: #a40e26; }
        .btn-small { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
        .chat-screen { display: flex; flex-direction: column; height: 100vh; height: 100dvh; }
        .chat-header { background: #ffffff; border-bottom: 1px solid #e1e4e8; padding: 0.75rem 1rem; display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap; box-shadow: 0 1px 0 rgba(27,31,35,0.04); }
        .chat-header-left { flex: 1; min-width: 0; }
        .chat-header-title { font-size: 1rem; font-weight: 600; margin-bottom: 0.125rem; }
        .chat-header-subtitle { font-size: 0.7rem; color: #57606a; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .user-name-display { color: #0969da; font-weight: 600; cursor: pointer; text-decoration: underline; text-decoration-style: dotted; }
        .user-name-display:hover { text-decoration-style: solid; }
        .participants-badge { background: #ddf4ff; color: #0969da; padding: 0.125rem 0.375rem; border-radius: 10px; font-size: 0.65rem; font-weight: 600; }
        .chat-main { flex: 1; display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
        .messages-container { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 1rem; background: #fafbfc; -webkit-overflow-scrolling: touch; }
        .messages-container::-webkit-scrollbar { width: 6px; }
        .messages-container::-webkit-scrollbar-track { background: transparent; }
        .messages-container::-webkit-scrollbar-thumb { background: #d0d7de; border-radius: 3px; }
        .message-wrapper { margin-bottom: 0.625rem; display: flex; flex-direction: column; }
        .message-wrapper.sent { align-items: flex-end; }
        .message-wrapper.received { align-items: flex-start; }
        .message-wrapper.system { align-items: center; }
        .message { max-width: 75%; padding: 0.5rem 0.75rem; border-radius: 8px; word-wrap: break-word; box-shadow: 0 1px 2px rgba(27,31,35,0.08); font-size: 0.875rem; }
        .message.sent { background: #ddf4ff; color: #0969da; border: 1px solid #b6e3ff; border-bottom-right-radius: 2px; }
        .message.received { background: #ffffff; color: #24292f; border: 1px solid #e1e4e8; border-bottom-left-radius: 2px; }
        .message.system { background: #f6f8fa; color: #57606a; border: 1px solid #e1e4e8; font-size: 0.75rem; padding: 0.375rem 0.75rem; font-style: italic; max-width: 90%; }
        .message.system.join { border-left: 3px solid #2da44e; }
        .message.system.leave { border-left: 3px solid #cf222e; }
        .message.system.rename { border-left: 3px solid #0969da; }
        .message.deleted { opacity: 0.5; font-style: italic; }
        .message-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.25rem; gap: 0.5rem; }
        .message-username { font-weight: 600; font-size: 0.75rem; }
        .message.sent .message-username { color: #0550ae; }
        .message.received .message-username { color: #24292f; }
        .message-time { font-size: 0.65rem; opacity: 0.65; white-space: nowrap; }
        .message-text { line-height: 1.4; word-break: break-word; }
        .message-edited { font-size: 0.65rem; font-style: italic; opacity: 0.65; margin-top: 0.125rem; }
        .message-actions { display: flex; gap: 0.25rem; margin-top: 0.375rem; flex-wrap: wrap; }
        .input-area { background: #ffffff; border-top: 1px solid #e1e4e8; padding: 0.75rem 1rem; padding-bottom: calc(0.75rem + var(--safe-bottom)); display: flex; gap: 0.5rem; align-items: center; box-shadow: 0 -1px 0 rgba(27,31,35,0.04); }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(27,31,36,0.4); z-index: 1000; align-items: center; justify-content: center; padding: 1rem; }
        .modal.active { display: flex; animation: fadeIn 0.15s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-content { background: #ffffff; border: 1px solid #e1e4e8; border-radius: 8px; padding: 1.25rem; max-width: 380px; width: 90%; box-shadow: 0 8px 24px rgba(27,31,35,0.15); animation: slideUp 0.2s ease; max-height: 80vh; overflow-y: auto; }
        @keyframes slideUp { from { transform: translateY(10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .notification { position: fixed; top: 1rem; right: 1rem; background: #ffffff; border: 1px solid #e1e4e8; border-radius: 6px; padding: 0.75rem 1rem; box-shadow: 0 4px 12px rgba(27,31,35,0.15); z-index: 2000; min-width: 260px; max-width: 90%; animation: slideIn 0.25s ease; }
        @keyframes slideIn { from { transform: translateX(400px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .notification.success { border-left: 3px solid #2da44e; }
        .notification.error { border-left: 3px solid #cf222e; }
        .notification.info { border-left: 3px solid #0969da; }
        .notification.warning { border-left: 3px solid #d4a72c; }
        .expiry-warning { background: #fff8c5; border-bottom: 1px solid #d4a72c; color: #633c01; padding: 0.625rem; text-align: center; font-size: 0.75rem; }
        .header-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .modal-title { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.75rem; color: #24292f; }
        .modal-message { margin-bottom: 0; color: #57606a; font-size: 0.875rem; word-break: break-word; white-space: pre-wrap; line-height: 1.5; }
        .modal-footer { display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem; flex-wrap: wrap; }
        .warning-icon { font-size: 2.5rem; text-align: center; margin-bottom: 0.75rem; }
        .participants-list { margin-top: 0.5rem; padding: 0.5rem; background: #f6f8fa; border-radius: 4px; font-size: 0.75rem; }
        .participant-item { display: flex; align-items: center; gap: 0.375rem; padding: 0.25rem 0; }
        .participant-dot { width: 8px; height: 8px; border-radius: 50%; background: #2da44e; }
        @media (max-width: 768px) {
            .message { max-width: 85%; font-size: 0.875rem; }
            .input { font-size: 16px; }
            .chat-header { padding: 0.625rem 0.875rem; }
            .messages-container { padding: 0.75rem; }
            .input-area { padding: 0.625rem 0.875rem; padding-bottom: calc(0.625rem + var(--safe-bottom)); }
            .header-actions { width: 100%; }
            .btn { font-size: 0.8125rem; padding: 0.5rem 0.875rem; }
            .notification { min-width: 240px; right: 0.75rem; top: 0.75rem; }
            .modal-content { padding: 1rem; max-width: 340px; }
        }
        @media (max-width: 480px) {
            .logo { font-size: 1.125rem; }
            .tagline { font-size: 0.65rem; }
            .message { max-width: 90%; }
            .modal-content { max-width: 95%; }
        }
    </style>
</head>
<body>
    <div id="createScreen" style="display: none;">
        <div class="header">
            <div class="logo">🔒 xsukax E2E Encrypted PHP Chat</div>
            <div class="tagline">End-to-end encrypted • Zero-knowledge • Auto-deletes after 24h • Max 2 users</div>
        </div>
        <div class="centered-form">
            <div class="card">
                <h2 style="font-size: 1.5rem; font-weight: 600; margin-bottom: 1rem; color: #24292f;">Create New Chat</h2>
                <div style="margin-bottom: 0.875rem;">
                    <label style="display: block; margin-bottom: 0.375rem; font-weight: 500; font-size: 0.875rem;">Chat Password</label>
                    <input type="password" id="createPassword" class="input" placeholder="Enter strong password (min 8 chars)">
                    <p style="font-size: 0.75rem; color: #57606a; margin-top: 0.375rem;">All messages are encrypted end-to-end. Server cannot decrypt your messages.</p>
                </div>
                <button onclick="createChat()" class="btn btn-primary" style="width: 100%;">Create Secure Chat</button>
            </div>
        </div>
    </div>

    <div id="joinScreen" style="display: none;">
        <div class="header">
            <div class="logo">🔒 xsukax E2E Encrypted PHP Chat</div>
            <div class="tagline">End-to-end encrypted • Zero-knowledge • Auto-deletes after 24h • Max 2 users</div>
        </div>
        <div class="centered-form">
            <div class="card">
                <h2 style="font-size: 1.5rem; font-weight: 600; margin-bottom: 1rem; color: #24292f;">Join Encrypted Chat</h2>
                <div style="margin-bottom: 0.875rem;">
                    <label style="display: block; margin-bottom: 0.375rem; font-weight: 500; font-size: 0.875rem;">Chat ID</label>
                    <input type="text" id="joinChatId" class="input" readonly>
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.375rem; font-weight: 500; font-size: 0.875rem;">Password</label>
                    <input type="password" id="joinPassword" class="input" placeholder="Enter chat password">
                </div>
                <button onclick="joinChat()" class="btn btn-primary" style="width: 100%;">Unlock Chat</button>
            </div>
        </div>
    </div>

    <div id="roomFullScreen" style="display: none;">
        <div class="header">
            <div class="logo">🔒 xsukax E2E Encrypted PHP Chat</div>
            <div class="tagline">End-to-end encrypted • Zero-knowledge • Auto-deletes after 24h • Max 2 users</div>
        </div>
        <div class="centered-form">
            <div class="card" style="text-align: center;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">🚫</div>
                <h2 style="font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; color: #cf222e;">Room Full</h2>
                <p style="color: #57606a; margin-bottom: 1rem;">This chat room already has 2 participants. Please wait for someone to leave or create a new chat.</p>
                <button onclick="window.location.href=location.pathname" class="btn btn-primary">Create New Chat</button>
            </div>
        </div>
    </div>

    <div id="chatScreen" class="chat-screen" style="display: none;">
        <div class="chat-header">
            <div class="chat-header-left">
                <div class="chat-header-title">🔒 xsukax E2E Encrypted PHP Chat</div>
                <div class="chat-header-subtitle">
                    <span>You: <span id="currentUserName" class="user-name-display" onclick="showNicknameModal()" title="Click to change nickname"></span></span>
                    <span class="participants-badge" id="participantsBadge">1/2 online</span>
                </div>
            </div>
            <div class="header-actions">
                <button onclick="showParticipantsModal()" class="btn btn-small">👥 Users</button>
                <button onclick="copyShareLink()" class="btn btn-small">📋 Share</button>
                <button onclick="showDestroyModal()" class="btn btn-small btn-danger">💥 Destroy</button>
            </div>
        </div>
        <div id="expiryWarning" class="expiry-warning" style="display: none;"></div>
        <div class="chat-main">
            <div class="messages-container" id="messagesContainer"></div>
            <div class="input-area">
                <input type="text" id="messageInput" class="input" placeholder="Type message..." onkeypress="if(event.key==='Enter')sendMessage()" style="flex: 1;">
                <button onclick="sendMessage()" class="btn btn-primary">Send</button>
            </div>
        </div>
    </div>

    <div id="infoModal" class="modal">
        <div class="modal-content">
            <h3 id="infoModalTitle" class="modal-title"></h3>
            <p id="infoModalMessage" class="modal-message"></p>
            <div class="modal-footer">
                <button onclick="closeInfoModal()" class="btn">Close</button>
                <button id="infoModalAction" onclick="infoModalActionCallback()" class="btn btn-primary" style="display: none;"></button>
            </div>
        </div>
    </div>

    <div id="confirmModal" class="modal">
        <div class="modal-content">
            <div id="confirmModalIcon" class="warning-icon" style="display: none;">⚠️</div>
            <h3 id="confirmModalTitle" class="modal-title" style="text-align: center;"></h3>
            <p id="confirmModalMessage" class="modal-message" style="text-align: center;"></p>
            <div class="modal-footer">
                <button onclick="closeConfirmModal()" class="btn">Cancel</button>
                <button id="confirmModalAction" onclick="confirmModalCallback()" class="btn btn-primary"></button>
            </div>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Edit Message</h3>
            <textarea id="editInput" class="input" rows="4" placeholder="Edit message..." style="resize: vertical; margin-bottom: 0;"></textarea>
            <div class="modal-footer">
                <button onclick="closeEditModal()" class="btn">Cancel</button>
                <button onclick="saveEdit()" class="btn btn-primary">Save</button>
            </div>
        </div>
    </div>

    <div id="nicknameModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Change Nickname</h3>
            <p style="font-size: 0.8rem; color: #57606a; margin-bottom: 0.75rem;">Your nickname is visible to other participants in this chat.</p>
            <input type="text" id="nicknameInput" class="input" placeholder="Enter new nickname..." maxlength="20">
            <div class="modal-footer">
                <button onclick="closeNicknameModal()" class="btn">Cancel</button>
                <button onclick="saveNickname()" class="btn btn-primary">Save</button>
            </div>
        </div>
    </div>

    <div id="participantsModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">👥 Online Participants</h3>
            <div id="participantsList" class="participants-list"></div>
            <div class="modal-footer">
                <button onclick="closeParticipantsModal()" class="btn">Close</button>
            </div>
        </div>
    </div>

    <script>
        let currentChatId, currentUserId, currentUserName, encryptionKey;
        let lastMessageId = 0, pollInterval, heartbeatInterval, displayedMessages = new Map();
        let editingMessageId, confirmCallback = null, participants = [];
        let messageVersions = {};
        let hasJoinedRoom = false;

        const adjectives = ['Swift', 'Bright', 'Clever', 'Gentle', 'Bold', 'Calm', 'Noble', 'Keen', 'Wise', 'Brave'];
        const nouns = ['Phoenix', 'Dragon', 'Eagle', 'Wolf', 'Tiger', 'Falcon', 'Bear', 'Fox', 'Hawk', 'Lion'];

        function generateUserName() {
            return adjectives[Math.floor(Math.random() * adjectives.length)] + 
                   nouns[Math.floor(Math.random() * nouns.length)] + 
                   (Math.floor(Math.random() * 999) + 1);
        }

        function getUserId(chatId) {
            let id = localStorage.getItem(`user_id_${chatId}`);
            if (!id) { id = crypto.randomUUID(); localStorage.setItem(`user_id_${chatId}`, id); }
            return id;
        }

        function getUserName(chatId) {
            let name = localStorage.getItem(`user_name_${chatId}`);
            if (!name) { name = generateUserName(); localStorage.setItem(`user_name_${chatId}`, name); }
            return name;
        }

        function setUserName(chatId, name) {
            localStorage.setItem(`user_name_${chatId}`, name);
            currentUserName = name;
            document.getElementById('currentUserName').textContent = name;
        }

        function arrayBufferToBase64(buf) {
            const bytes = new Uint8Array(buf);
            let bin = '';
            for (let i = 0; i < bytes.length; i += 8192) {
                bin += String.fromCharCode(...bytes.subarray(i, Math.min(i + 8192, bytes.length)));
            }
            return btoa(bin);
        }

        function base64ToArrayBuffer(b64) {
            const bin = atob(b64);
            const bytes = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
            return bytes.buffer;
        }

        async function deriveKey(pwd, salt) {
            const enc = new TextEncoder();
            const keyMat = await crypto.subtle.importKey('raw', enc.encode(pwd), 'PBKDF2', false, ['deriveKey']);
            return crypto.subtle.deriveKey(
                { name: 'PBKDF2', salt: enc.encode(salt), iterations: 100000, hash: 'SHA-256' },
                keyMat, { name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']
            );
        }

        async function hashPassword(pwd) {
            const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(pwd));
            return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2, '0')).join('');
        }

        async function encrypt(text, key) {
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const enc = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, new TextEncoder().encode(text));
            const combined = new Uint8Array(iv.length + enc.byteLength);
            combined.set(iv);
            combined.set(new Uint8Array(enc), iv.length);
            return arrayBufferToBase64(combined.buffer);
        }

        async function decrypt(encData, key) {
            try {
                const data = new Uint8Array(base64ToArrayBuffer(encData));
                const dec = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: data.slice(0, 12) }, key, data.slice(12));
                return new TextDecoder().decode(dec);
            } catch { return null; }
        }

        function notify(msg, type = 'info') {
            const n = document.createElement('div');
            n.className = `notification ${type}`;
            const icons = { success: '✔', error: '✗', warning: '⚠', info: 'ℹ' };
            n.innerHTML = `<div style="font-weight: 600; margin-bottom: 0.25rem; font-size: 0.8125rem;">${icons[type] || 'ℹ'} ${type.toUpperCase()}</div><div style="font-size: 0.8125rem;">${escapeHtml(msg)}</div>`;
            document.body.appendChild(n);
            setTimeout(() => n.remove(), 3500);
        }

        function showInfoModal(title, msg, actionText, callback) {
            document.getElementById('infoModalTitle').textContent = title;
            document.getElementById('infoModalMessage').textContent = msg;
            const btn = document.getElementById('infoModalAction');
            if (actionText && callback) {
                btn.textContent = actionText;
                btn.style.display = 'block';
                window.infoModalActionCallback = callback;
            } else {
                btn.style.display = 'none';
            }
            document.getElementById('infoModal').classList.add('active');
        }

        function closeInfoModal() { document.getElementById('infoModal').classList.remove('active'); }

        function showConfirmModal(title, msg, actionText, callback, showWarning = false) {
            document.getElementById('confirmModalIcon').style.display = showWarning ? 'block' : 'none';
            document.getElementById('confirmModalTitle').textContent = title;
            document.getElementById('confirmModalMessage').textContent = msg;
            document.getElementById('confirmModalAction').textContent = actionText;
            document.getElementById('confirmModalAction').className = showWarning ? 'btn btn-danger' : 'btn btn-primary';
            confirmCallback = callback;
            document.getElementById('confirmModal').classList.add('active');
        }

        function closeConfirmModal() { document.getElementById('confirmModal').classList.remove('active'); confirmCallback = null; }
        function confirmModalCallback() { if (confirmCallback) { confirmCallback(); closeConfirmModal(); } }

        function showEditModal(id, text) {
            editingMessageId = id;
            document.getElementById('editInput').value = text;
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() { document.getElementById('editModal').classList.remove('active'); }

        function showNicknameModal() {
            document.getElementById('nicknameInput').value = currentUserName;
            document.getElementById('nicknameModal').classList.add('active');
            document.getElementById('nicknameInput').focus();
        }

        function closeNicknameModal() { document.getElementById('nicknameModal').classList.remove('active'); }

        function showParticipantsModal() {
            updateParticipantsList();
            document.getElementById('participantsModal').classList.add('active');
        }

        function closeParticipantsModal() { document.getElementById('participantsModal').classList.remove('active'); }

        function showDestroyModal() {
            showConfirmModal('Destroy Chat?', 'This will permanently delete this chat and ALL messages for EVERYONE.\n\nThis action CANNOT be undone!', 'Destroy Forever', destroyChat, true);
        }

        function showDeleteMessageModal(id) {
            showConfirmModal('Delete Message?', 'Are you sure you want to delete this message?', 'Delete', () => deleteMessage(id), false);
        }

        async function updateParticipantsList() {
            const list = document.getElementById('participantsList');
            let html = '';
            for (const p of participants) {
                let name = 'Unknown';
                if (p.encrypted_name) {
                    try {
                        const dec = await decrypt(p.encrypted_name, encryptionKey);
                        if (dec) name = dec;
                    } catch {}
                }
                const isYou = p.user_id === currentUserId;
                html += `<div class="participant-item"><span class="participant-dot"></span><span>${escapeHtml(name)}${isYou ? ' (you)' : ''}</span></div>`;
            }
            list.innerHTML = html || '<div style="color: #57606a;">No participants</div>';
        }

        function updateParticipantsBadge() {
            document.getElementById('participantsBadge').textContent = `${participants.length}/2 online`;
        }

        async function saveNickname() {
            const newName = document.getElementById('nicknameInput').value.trim();
            if (!newName || newName.length < 1 || newName.length > 20) {
                notify('Nickname must be 1-20 characters', 'error');
                return;
            }
            const oldName = currentUserName;
            if (newName === oldName) { closeNicknameModal(); return; }

            try {
                const encName = await encrypt(newName, encryptionKey);
                const renameMsg = await encrypt(JSON.stringify({ type: 'rename', oldName, newName }), encryptionKey);
                
                const fd = new FormData();
                fd.append('action', 'update_nickname');
                fd.append('chat_id', currentChatId);
                fd.append('user_id', currentUserId);
                fd.append('encrypted_name', encName);
                fd.append('encrypted_rename_msg', renameMsg);
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    setUserName(currentChatId, newName);
                    closeNicknameModal();
                    notify('Nickname changed to ' + newName, 'success');
                } else {
                    notify('Failed to update nickname', 'error');
                }
            } catch (e) {
                notify('Error: ' + e.message, 'error');
            }
        }

        async function saveEdit() {
            const text = document.getElementById('editInput').value.trim();
            if (!text) { notify('Message cannot be empty', 'error'); return; }

            try {
                const enc = await encrypt(JSON.stringify({ text, userName: currentUserName }), encryptionKey);
                const fd = new FormData();
                fd.append('action', 'edit_message');
                fd.append('message_id', editingMessageId);
                fd.append('user_id', currentUserId);
                fd.append('encrypted_content', enc);
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    messageVersions[editingMessageId] = data.version;
                    
                    // Update DOM immediately for the editing user
                    const msgEl = document.getElementById(`msg-${editingMessageId}`);
                    if (msgEl) {
                        msgEl.querySelector('.message-text').textContent = text;
                        
                        // Update the Edit button's onclick with new text
                        const editBtn = msgEl.querySelector('.message-actions button:first-child');
                        if (editBtn) {
                            editBtn.onclick = () => showEditModal(editingMessageId, text);
                        }
                        
                        // Add (edited) label if not already present
                        if (!msgEl.querySelector('.message-edited')) {
                            const editedDiv = document.createElement('div');
                            editedDiv.className = 'message-edited';
                            editedDiv.textContent = '(edited)';
                            const actions = msgEl.querySelector('.message-actions');
                            if (actions) {
                                msgEl.querySelector('.message').insertBefore(editedDiv, actions);
                            } else {
                                msgEl.querySelector('.message').appendChild(editedDiv);
                            }
                        }
                    }
                    
                    notify('Message updated', 'success');
                    closeEditModal();
                } else {
                    notify('Failed to update message', 'error');
                }
            } catch (e) {
                notify('Error: ' + e.message, 'error');
            }
        }

        async function deleteMessage(id) {
            try {
                const fd = new FormData();
                fd.append('action', 'delete_message');
                fd.append('message_id', id);
                fd.append('user_id', currentUserId);
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    messageVersions[id] = data.version;
                    
                    // Update DOM immediately for the deleting user
                    const msgEl = document.getElementById(`msg-${id}`);
                    if (msgEl) {
                        const bubble = msgEl.querySelector('.message');
                        bubble.classList.add('deleted');
                        msgEl.querySelector('.message-text').textContent = '[Message deleted]';
                        const actions = msgEl.querySelector('.message-actions');
                        if (actions) actions.remove();
                        const edited = msgEl.querySelector('.message-edited');
                        if (edited) edited.remove();
                    }
                    
                    notify('Message deleted', 'success');
                } else {
                    notify('Failed to delete message', 'error');
                }
            } catch (e) {
                notify('Error: ' + e.message, 'error');
            }
        }

        async function createChat() {
            const pwd = document.getElementById('createPassword').value;
            if (!pwd || pwd.length < 8) { notify('Password must be 8+ characters', 'error'); return; }

            try {
                const fd = new FormData();
                fd.append('action', 'create_chat');
                fd.append('password_hash', await hashPassword(pwd));
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    currentChatId = data.chat_id;
                    currentUserId = getUserId(currentChatId);
                    currentUserName = getUserName(currentChatId);
                    encryptionKey = await deriveKey(pwd, currentChatId);
                    
                    await joinRoom();
                    
                    const url = `${location.origin}${location.pathname}?chat=${currentChatId}`;
                    showInfoModal('Chat Created!', `Share this link:\n\n${url}\n\nExpires in 24 hours. Max 2 users.`, 'Copy Link', () => {
                        navigator.clipboard.writeText(url);
                        notify('Link copied to clipboard', 'success');
                        closeInfoModal();
                    });
                    
                    showChatScreen();
                } else {
                    notify(data.error || 'Failed to create chat', 'error');
                }
            } catch (e) {
                notify('Error: ' + e.message, 'error');
            }
        }

        async function joinChat() {
            const pwd = document.getElementById('joinPassword').value;
            if (!pwd) { notify('Please enter password', 'error'); return; }

            try {
                currentChatId = document.getElementById('joinChatId').value;
                currentUserId = getUserId(currentChatId);
                
                const fd = new FormData();
                fd.append('action', 'verify_chat');
                fd.append('chat_id', currentChatId);
                fd.append('user_id', currentUserId);
                fd.append('password_hash', await hashPassword(pwd));
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    currentUserName = getUserName(currentChatId);
                    encryptionKey = await deriveKey(pwd, currentChatId);
                    
                    await joinRoom();
                    
                    notify('Successfully joined chat', 'success');
                    if (data.remaining_time < 3600) {
                        document.getElementById('expiryWarning').textContent = `⚠️ Chat expires in ${Math.floor(data.remaining_time / 60)} minutes`;
                        document.getElementById('expiryWarning').style.display = 'block';
                    }
                    showChatScreen();
                } else if (data.error === 'Room is full (max 2 users)') {
                    document.getElementById('joinScreen').style.display = 'none';
                    document.getElementById('roomFullScreen').style.display = 'block';
                } else {
                    notify(data.error || 'Failed to join chat', 'error');
                }
            } catch (e) {
                notify('Error: ' + e.message, 'error');
            }
        }

        async function joinRoom(sendNotification = true) {
            try {
                const encName = await encrypt(currentUserName, encryptionKey);
                const fd = new FormData();
                fd.append('action', 'join_room');
                fd.append('chat_id', currentChatId);
                fd.append('user_id', currentUserId);
                fd.append('encrypted_name', encName);
                
                // Only send join notification on first join
                if (sendNotification && !hasJoinedRoom) {
                    const joinMsg = await encrypt(JSON.stringify({ type: 'join', userName: currentUserName }), encryptionKey);
                    fd.append('encrypted_join_msg', joinMsg);
                }
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    hasJoinedRoom = true;
                    participants = data.participants || [];
                    updateParticipantsBadge();
                } else if (data.error === 'Room is full') {
                    document.getElementById('joinScreen').style.display = 'none';
                    document.getElementById('roomFullScreen').style.display = 'block';
                    throw new Error('Room is full');
                }
            } catch (e) {
                throw e;
            }
        }

        async function leaveRoom() {
            if (!hasJoinedRoom) return;
            try {
                const leaveMsg = await encrypt(JSON.stringify({ type: 'leave', userName: currentUserName }), encryptionKey);
                const fd = new FormData();
                fd.append('action', 'leave_room');
                fd.append('chat_id', currentChatId);
                fd.append('user_id', currentUserId);
                fd.append('encrypted_leave_msg', leaveMsg);
                navigator.sendBeacon('', fd);
                hasJoinedRoom = false;
            } catch {}
        }

        async function sendHeartbeat() {
            try {
                const encName = await encrypt(currentUserName, encryptionKey);
                const fd = new FormData();
                fd.append('action', 'heartbeat');
                fd.append('chat_id', currentChatId);
                fd.append('user_id', currentUserId);
                fd.append('encrypted_name', encName);
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    participants = data.participants || [];
                    updateParticipantsBadge();
                }
            } catch {}
        }

        function showChatScreen() {
            document.getElementById('createScreen').style.display = 'none';
            document.getElementById('joinScreen').style.display = 'none';
            document.getElementById('roomFullScreen').style.display = 'none';
            document.getElementById('chatScreen').style.display = 'flex';
            document.getElementById('currentUserName').textContent = currentUserName;
            loadMessages();
            pollInterval = setInterval(loadMessages, 2000);
            heartbeatInterval = setInterval(sendHeartbeat, 10000);
        }

        function copyShareLink() {
            navigator.clipboard.writeText(`${location.origin}${location.pathname}?chat=${currentChatId}`);
            notify('Share link copied to clipboard', 'success');
        }

        async function destroyChat() {
            try {
                const fd = new FormData();
                fd.append('action', 'destroy_chat');
                fd.append('chat_id', currentChatId);
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    if (pollInterval) clearInterval(pollInterval);
                    if (heartbeatInterval) clearInterval(heartbeatInterval);
                    localStorage.removeItem(`user_id_${currentChatId}`);
                    localStorage.removeItem(`user_name_${currentChatId}`);
                    
                    showInfoModal('Chat Destroyed', 'This chat has been permanently deleted from the database.', null, null);
                    setTimeout(() => { window.location.href = location.pathname; }, 2000);
                } else {
                    notify(data.error || 'Failed to destroy chat', 'error');
                }
            } catch (e) {
                notify('Error: ' + e.message, 'error');
            }
        }

        async function sendMessage() {
            const input = document.getElementById('messageInput');
            const msg = input.value.trim();
            if (!msg) return;

            try {
                const enc = await encrypt(JSON.stringify({ text: msg, userName: currentUserName }), encryptionKey);
                const fd = new FormData();
                fd.append('action', 'send_message');
                fd.append('chat_id', currentChatId);
                fd.append('user_id', currentUserId);
                fd.append('encrypted_content', enc);
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    input.value = '';
                    setTimeout(loadMessages, 100);
                }
            } catch (e) {
                notify('Failed to send message', 'error');
            }
        }

        async function loadMessages() {
            try {
                const fd = new FormData();
                fd.append('action', 'get_messages');
                fd.append('chat_id', currentChatId);
                fd.append('last_id', lastMessageId);
                fd.append('known_versions', JSON.stringify(messageVersions));
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (!data.success) return;
                
                const container = document.getElementById('messagesContainer');
                const shouldScroll = container.scrollHeight - container.scrollTop <= container.clientHeight + 100;
                
                // Handle updates to existing messages
                if (data.updates && data.updates.length > 0) {
                    for (const msg of data.updates) {
                        messageVersions[msg.id] = msg.version;
                        const existingEl = document.getElementById(`msg-${msg.id}`);
                        if (existingEl) {
                            if (msg.deleted) {
                                existingEl.querySelector('.message').classList.add('deleted');
                                existingEl.querySelector('.message-text').textContent = '[Message deleted]';
                                const actions = existingEl.querySelector('.message-actions');
                                if (actions) actions.remove();
                                const edited = existingEl.querySelector('.message-edited');
                                if (edited) edited.remove();
                            } else if (msg.edited) {
                                const dec = await decrypt(msg.encrypted_content, encryptionKey);
                                if (dec) {
                                    let msgData;
                                    try { msgData = JSON.parse(dec); } catch { msgData = { text: dec }; }
                                    existingEl.querySelector('.message-text').textContent = msgData.text;
                                    if (!existingEl.querySelector('.message-edited')) {
                                        const editedDiv = document.createElement('div');
                                        editedDiv.className = 'message-edited';
                                        editedDiv.textContent = '(edited)';
                                        existingEl.querySelector('.message').insertBefore(editedDiv, existingEl.querySelector('.message-actions'));
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Handle new messages
                if (data.messages && data.messages.length > 0) {
                    for (const msg of data.messages) {
                        if (displayedMessages.has(msg.id)) continue;
                        
                        messageVersions[msg.id] = msg.version;
                        
                        if (msg.msg_type === 'join' || msg.msg_type === 'leave' || msg.msg_type === 'rename') {
                            await renderSystemMessage(msg, container);
                        } else if (!msg.deleted) {
                            await renderUserMessage(msg, container);
                        }
                        
                        displayedMessages.set(msg.id, msg.version);
                        lastMessageId = Math.max(lastMessageId, msg.id);
                    }
                    
                    if (shouldScroll) container.scrollTop = container.scrollHeight;
                }
            } catch (e) {
                console.error('Load messages error:', e);
            }
        }

        async function renderSystemMessage(msg, container) {
            const dec = await decrypt(msg.encrypted_content, encryptionKey);
            if (!dec) return;
            
            let sysData, text = '';
            try {
                sysData = JSON.parse(dec);
                if (sysData.type === 'join') {
                    text = `${sysData.userName} joined the chat`;
                } else if (sysData.type === 'leave') {
                    text = `${sysData.userName} left the chat`;
                } else if (sysData.type === 'timeout_leave') {
                    const name = await decrypt(sysData.encrypted_name, encryptionKey);
                    text = `${name || 'A user'} disconnected`;
                } else if (sysData.type === 'rename') {
                    text = `${sysData.oldName} changed their name to ${sysData.newName}`;
                }
            } catch {
                text = dec;
            }
            
            if (!text) return;
            
            const wrapper = document.createElement('div');
            wrapper.className = 'message-wrapper system';
            wrapper.id = `msg-${msg.id}`;
            
            const bubble = document.createElement('div');
            bubble.className = `message system ${msg.msg_type}`;
            bubble.innerHTML = `<span>${escapeHtml(text)}</span> <span class="message-time">${new Date(msg.timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>`;
            
            wrapper.appendChild(bubble);
            container.appendChild(wrapper);
        }

        async function renderUserMessage(msg, container) {
            const dec = await decrypt(msg.encrypted_content, encryptionKey);
            if (!dec) return;
            
            let msgData;
            try { msgData = JSON.parse(dec); } catch { msgData = { text: dec, userName: 'User' }; }
            
            const isMine = msg.user_id === currentUserId;
            const wrapper = document.createElement('div');
            wrapper.className = `message-wrapper ${isMine ? 'sent' : 'received'}`;
            wrapper.id = `msg-${msg.id}`;
            
            const bubble = document.createElement('div');
            bubble.className = `message ${isMine ? 'sent' : 'received'}`;
            
            bubble.innerHTML = `
                <div class="message-header">
                    <span class="message-username">${escapeHtml(msgData.userName || 'Anonymous')}</span>
                    <span class="message-time">${new Date(msg.timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                </div>
                <div class="message-text">${escapeHtml(msgData.text)}</div>
            `;
            
            if (msg.edited) {
                const editedDiv = document.createElement('div');
                editedDiv.className = 'message-edited';
                editedDiv.textContent = '(edited)';
                bubble.appendChild(editedDiv);
            }
            
            if (isMine) {
                const actions = document.createElement('div');
                actions.className = 'message-actions';
                actions.innerHTML = `
                    <button onclick="showEditModal(${msg.id}, ${JSON.stringify(msgData.text).replace(/"/g, '&quot;')})" class="btn btn-small">✏️ Edit</button>
                    <button onclick="showDeleteMessageModal(${msg.id})" class="btn btn-small btn-danger">🗑️</button>
                `;
                bubble.appendChild(actions);
            }
            
            wrapper.appendChild(bubble);
            container.appendChild(wrapper);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        window.addEventListener('load', () => {
            const chatParam = new URLSearchParams(location.search).get('chat');
            if (chatParam) {
                document.getElementById('joinChatId').value = chatParam;
                document.getElementById('joinScreen').style.display = 'block';
            } else {
                document.getElementById('createScreen').style.display = 'block';
            }
        });

        window.addEventListener('beforeunload', () => {
            if (pollInterval) clearInterval(pollInterval);
            if (heartbeatInterval) clearInterval(heartbeatInterval);
            if (currentChatId && currentUserId) leaveRoom();
        });

        // Only pause/resume heartbeat on visibility change, don't leave/rejoin
        window.addEventListener('visibilitychange', () => {
            if (!currentChatId || !encryptionKey) return;
            
            if (document.visibilityState === 'visible') {
                // Resume polling and heartbeat when tab becomes visible
                if (!pollInterval) {
                    loadMessages();
                    pollInterval = setInterval(loadMessages, 2000);
                }
                if (!heartbeatInterval) {
                    sendHeartbeat();
                    heartbeatInterval = setInterval(sendHeartbeat, 10000);
                }
            } else {
                // Optionally slow down polling when hidden (but don't leave)
                // The heartbeat will keep presence alive
            }
        });
    </script>
</body>
</html>
