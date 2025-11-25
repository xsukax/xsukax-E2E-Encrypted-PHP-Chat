<?php
error_reporting(0);
ini_set('display_errors', 0);
session_start();

define('DB_FILE', 'chats.db');
define('CHAT_LIFETIME', 24 * 60 * 60); // 24 hours

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
            edited INTEGER DEFAULT 0
        )');
        
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
            $db->exec("DELETE FROM chats WHERE id = '" . SQLite3::escapeString($chatId) . "'");
        }
    } catch (Exception $e) {
    }
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
            $stmt = $db->prepare('SELECT password_hash, created_at FROM chats WHERE id = :id');
            $stmt->bindValue(':id', $_POST['chat_id'] ?? '', SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Chat not found']);
            } elseif (password_verify($_POST['password_hash'] ?? '', $row['password_hash'])) {
                echo json_encode(['success' => true, 'remaining_time' => CHAT_LIFETIME - (time() - $row['created_at'])]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid password']);
            }
            
        } elseif ($action === 'send_message') {
            $stmt = $db->prepare('INSERT INTO messages (chat_id, user_id, encrypted_content, timestamp, edited) VALUES (:chat_id, :user_id, :content, :time, 0)');
            $stmt->bindValue(':chat_id', $_POST['chat_id'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $_POST['user_id'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':content', $_POST['encrypted_content'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['success' => true]);
            
        } elseif ($action === 'edit_message') {
            $stmt = $db->prepare('UPDATE messages SET encrypted_content = :content, edited = 1 WHERE id = :id AND user_id = :user_id');
            $stmt->bindValue(':content', $_POST['encrypted_content'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':id', intval($_POST['message_id'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':user_id', $_POST['user_id'] ?? '', SQLITE3_TEXT);
            $stmt->execute();
            echo json_encode(['success' => $db->changes() > 0]);
            
        } elseif ($action === 'delete_message') {
            $stmt = $db->prepare('DELETE FROM messages WHERE id = :id AND user_id = :user_id');
            $stmt->bindValue(':id', intval($_POST['message_id'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':user_id', $_POST['user_id'] ?? '', SQLITE3_TEXT);
            $stmt->execute();
            echo json_encode(['success' => $db->changes() > 0]);
            
        } elseif ($action === 'get_messages') {
            $stmt = $db->prepare('SELECT id, user_id, encrypted_content, timestamp, edited FROM messages WHERE chat_id = :chat_id AND id > :last_id ORDER BY id ASC LIMIT 50');
            $stmt->bindValue(':chat_id', $_POST['chat_id'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':last_id', intval($_POST['last_id'] ?? 0), SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            $messages = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $messages[] = [
                    'id' => (int)$row['id'],
                    'user_id' => $row['user_id'],
                    'encrypted_content' => $row['encrypted_content'],
                    'timestamp' => (int)$row['timestamp'],
                    'edited' => (int)$row['edited']
                ];
            }
            echo json_encode(['success' => true, 'messages' => $messages]);
            
        } elseif ($action === 'destroy_chat') {
            $chatId = $_POST['chat_id'] ?? '';
            if (empty($chatId)) throw new Exception('Chat ID required');
            
            $stmt = $db->prepare('SELECT id FROM chats WHERE id = :id');
            $stmt->bindValue(':id', $chatId, SQLITE3_TEXT);
            $result = $stmt->execute();
            
            if ($result->fetchArray(SQLITE3_ASSOC)) {
                $stmt = $db->prepare('DELETE FROM messages WHERE chat_id = :chat_id');
                $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
                $stmt->execute();
                
                $stmt = $db->prepare('DELETE FROM chats WHERE id = :id');
                $stmt->bindValue(':id', $chatId, SQLITE3_TEXT);
                $stmt->execute();
                
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
        .chat-header-subtitle { font-size: 0.7rem; color: #57606a; }
        .chat-main { flex: 1; display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
        .messages-container { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 1rem; background: #fafbfc; -webkit-overflow-scrolling: touch; }
        .messages-container::-webkit-scrollbar { width: 6px; }
        .messages-container::-webkit-scrollbar-track { background: transparent; }
        .messages-container::-webkit-scrollbar-thumb { background: #d0d7de; border-radius: 3px; }
        .message-wrapper { margin-bottom: 0.625rem; display: flex; flex-direction: column; }
        .message-wrapper.sent { align-items: flex-end; }
        .message-wrapper.received { align-items: flex-start; }
        .message { max-width: 75%; padding: 0.5rem 0.75rem; border-radius: 8px; word-wrap: break-word; box-shadow: 0 1px 2px rgba(27,31,35,0.08); font-size: 0.875rem; }
        .message.sent { background: #ddf4ff; color: #0969da; border: 1px solid #b6e3ff; border-bottom-right-radius: 2px; }
        .message.received { background: #ffffff; color: #24292f; border: 1px solid #e1e4e8; border-bottom-left-radius: 2px; }
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
            <div class="tagline">End-to-end encrypted • Zero-knowledge • Auto-deletes after 24h</div>
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
            <div class="tagline">End-to-end encrypted • Zero-knowledge • Auto-deletes after 24h</div>
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

    <div id="chatScreen" class="chat-screen" style="display: none;">
        <div class="chat-header">
            <div class="chat-header-left">
                <div class="chat-header-title">xsukax E2E Encrypted PHP Chat</div>
                <div class="chat-header-subtitle">You: <span id="currentUserName" style="color: #0969da; font-weight: 600;"></span></div>
            </div>
            <div class="header-actions">
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

    <script>
        let currentChatId, currentUserId, currentUserName, encryptionKey;
        let lastMessageId = 0, pollInterval, displayedMessageIds = new Set(), editingMessageId;
        let confirmCallback = null;

        const adjectives = ['Swift', 'Bright', 'Clever', 'Gentle', 'Bold', 'Calm', 'Noble', 'Keen', 'Wise', 'Brave'];
        const nouns = ['Phoenix', 'Dragon', 'Eagle', 'Wolf', 'Tiger', 'Falcon', 'Bear', 'Fox', 'Hawk', 'Lion'];

        function generateUserName() {
            return adjectives[Math.floor(Math.random() * adjectives.length)] + 
                   nouns[Math.floor(Math.random() * nouns.length)] + 
                   (Math.floor(Math.random() * 999) + 1);
        }

        function getUserId(chatId) {
            let userId = localStorage.getItem(`user_id_${chatId}`);
            if (!userId) {
                userId = crypto.randomUUID();
                localStorage.setItem(`user_id_${chatId}`, userId);
            }
            return userId;
        }

        function getUserName(chatId) {
            let userName = localStorage.getItem(`user_name_${chatId}`);
            if (!userName) {
                userName = generateUserName();
                localStorage.setItem(`user_name_${chatId}`, userName);
            }
            return userName;
        }

        function arrayBufferToBase64(buffer) {
            const bytes = new Uint8Array(buffer);
            let binary = '';
            const chunkSize = 8192;
            for (let i = 0; i < bytes.length; i += chunkSize) {
                binary += String.fromCharCode(...bytes.subarray(i, Math.min(i + chunkSize, bytes.length)));
            }
            return btoa(binary);
        }

        function base64ToArrayBuffer(base64) {
            const binary = atob(base64);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
            return bytes.buffer;
        }

        async function deriveKey(password, salt) {
            const encoder = new TextEncoder();
            const keyMaterial = await crypto.subtle.importKey('raw', encoder.encode(password), 'PBKDF2', false, ['deriveKey']);
            return crypto.subtle.deriveKey(
                { name: 'PBKDF2', salt: encoder.encode(salt), iterations: 100000, hash: 'SHA-256' },
                keyMaterial,
                { name: 'AES-GCM', length: 256 },
                true,
                ['encrypt', 'decrypt']
            );
        }

        async function hashPassword(password) {
            const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(password));
            return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2, '0')).join('');
        }

        async function encrypt(text, key) {
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const encrypted = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, new TextEncoder().encode(text));
            const combined = new Uint8Array(iv.length + encrypted.byteLength);
            combined.set(iv);
            combined.set(new Uint8Array(encrypted), iv.length);
            return arrayBufferToBase64(combined.buffer);
        }

        async function decrypt(encryptedData, key) {
            try {
                const data = new Uint8Array(base64ToArrayBuffer(encryptedData));
                const decrypted = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: data.slice(0, 12) }, key, data.slice(12));
                return new TextDecoder().decode(decrypted);
            } catch { return null; }
        }

        function notify(msg, type = 'info') {
            const n = document.createElement('div');
            n.className = `notification ${type}`;
            n.innerHTML = `<div style="font-weight: 600; margin-bottom: 0.25rem; font-size: 0.8125rem;">${type === 'success' ? '✓' : type === 'error' ? '✗' : type === 'warning' ? '⚠' : 'ℹ'} ${type.toUpperCase()}</div><div style="font-size: 0.8125rem;">${escapeHtml(msg)}</div>`;
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

        function closeInfoModal() {
            document.getElementById('infoModal').classList.remove('active');
        }

        function showConfirmModal(title, msg, actionText, callback, showWarning = false) {
            document.getElementById('confirmModalIcon').style.display = showWarning ? 'block' : 'none';
            document.getElementById('confirmModalTitle').textContent = title;
            document.getElementById('confirmModalMessage').textContent = msg;
            document.getElementById('confirmModalAction').textContent = actionText;
            document.getElementById('confirmModalAction').className = showWarning ? 'btn btn-danger' : 'btn btn-primary';
            confirmCallback = callback;
            document.getElementById('confirmModal').classList.add('active');
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').classList.remove('active');
            confirmCallback = null;
        }

        function confirmModalCallback() {
            if (confirmCallback) {
                confirmCallback();
                closeConfirmModal();
            }
        }

        function showEditModal(id, text) {
            editingMessageId = id;
            document.getElementById('editInput').value = text;
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function showDestroyModal() {
            showConfirmModal(
                'Destroy Chat?',
                'This will permanently delete this chat and ALL messages for EVERYONE.\n\nThis action CANNOT be undone!',
                'Destroy Forever',
                destroyChat,
                true
            );
        }

        function showDeleteMessageModal(id) {
            showConfirmModal(
                'Delete Message?',
                'Are you sure you want to delete this message?',
                'Delete',
                () => deleteMessage(id),
                false
            );
        }

        async function saveEdit() {
            const text = document.getElementById('editInput').value.trim();
            if (!text) {
                notify('Message cannot be empty', 'error');
                return;
            }

            try {
                const encrypted = await encrypt(JSON.stringify({ text, userName: currentUserName }), encryptionKey);
                const fd = new FormData();
                fd.append('action', 'edit_message');
                fd.append('message_id', editingMessageId);
                fd.append('user_id', currentUserId);
                fd.append('encrypted_content', encrypted);
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    notify('Message updated', 'success');
                    closeEditModal();
                    displayedMessageIds.clear();
                    lastMessageId = 0;
                    document.getElementById('messagesContainer').innerHTML = '';
                    loadMessages();
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
                    notify('Message deleted', 'success');
                    displayedMessageIds.clear();
                    lastMessageId = 0;
                    document.getElementById('messagesContainer').innerHTML = '';
                    loadMessages();
                } else {
                    notify('Failed to delete message', 'error');
                }
            } catch (e) {
                notify('Error: ' + e.message, 'error');
            }
        }

        async function createChat() {
            const pwd = document.getElementById('createPassword').value;
            if (!pwd || pwd.length < 8) {
                notify('Password must be 8+ characters', 'error');
                return;
            }

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
                    
                    const url = `${location.origin}${location.pathname}?chat=${currentChatId}`;
                    showInfoModal('Chat Created!', `Share this link:\n\n${url}\n\nExpires in 24 hours.`, 'Copy Link', () => {
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
            if (!pwd) {
                notify('Please enter password', 'error');
                return;
            }

            try {
                const fd = new FormData();
                fd.append('action', 'verify_chat');
                fd.append('chat_id', document.getElementById('joinChatId').value);
                fd.append('password_hash', await hashPassword(pwd));
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    currentChatId = document.getElementById('joinChatId').value;
                    currentUserId = getUserId(currentChatId);
                    currentUserName = getUserName(currentChatId);
                    encryptionKey = await deriveKey(pwd, currentChatId);
                    showChatScreen();
                    notify('Successfully joined chat', 'success');
                    if (data.remaining_time < 3600) {
                        document.getElementById('expiryWarning').textContent = `⚠️ Chat expires in ${Math.floor(data.remaining_time / 60)} minutes`;
                        document.getElementById('expiryWarning').style.display = 'block';
                    }
                } else {
                    notify(data.error || 'Failed to join chat', 'error');
                }
            } catch (e) {
                notify('Error: ' + e.message, 'error');
            }
        }

        function showChatScreen() {
            document.getElementById('createScreen').style.display = 'none';
            document.getElementById('joinScreen').style.display = 'none';
            document.getElementById('chatScreen').style.display = 'flex';
            document.getElementById('currentUserName').textContent = currentUserName;
            loadMessages();
            pollInterval = setInterval(loadMessages, 2000);
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
                    localStorage.removeItem(`user_id_${currentChatId}`);
                    localStorage.removeItem(`user_name_${currentChatId}`);
                    
                    showInfoModal('Chat Destroyed', 'This chat has been permanently deleted from the database.', null, null);
                    
                    setTimeout(() => {
                        window.location.href = location.pathname;
                    }, 2000);
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
                const encrypted = await encrypt(JSON.stringify({ text: msg, userName: currentUserName }), encryptionKey);
                const fd = new FormData();
                fd.append('action', 'send_message');
                fd.append('chat_id', currentChatId);
                fd.append('user_id', currentUserId);
                fd.append('encrypted_content', encrypted);
                
                await fetch('', { method: 'POST', body: fd });
                input.value = '';
                setTimeout(loadMessages, 100);
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
                
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success && data.messages.length) {
                    const container = document.getElementById('messagesContainer');
                    const shouldScroll = container.scrollHeight - container.scrollTop <= container.clientHeight + 100;
                    
                    for (const msg of data.messages) {
                        if (displayedMessageIds.has(msg.id)) continue;
                        
                        const decrypted = await decrypt(msg.encrypted_content, encryptionKey);
                        if (!decrypted) { 
                            displayedMessageIds.add(msg.id); 
                            continue; 
                        }
                        
                        let msgData;
                        try {
                            msgData = JSON.parse(decrypted);
                        } catch {
                            msgData = { text: decrypted, userName: 'User' };
                        }
                        
                        const isMine = msg.user_id === currentUserId;
                        const wrapper = document.createElement('div');
                        wrapper.className = `message-wrapper ${isMine ? 'sent' : 'received'}`;
                        
                        const bubble = document.createElement('div');
                        bubble.className = `message ${isMine ? 'sent' : 'received'}`;
                        
                        const header = document.createElement('div');
                        header.className = 'message-header';
                        header.innerHTML = `
                            <span class="message-username">${escapeHtml(msgData.userName || 'Anonymous')}</span>
                            <span class="message-time">${new Date(msg.timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                        `;
                        
                        const textDiv = document.createElement('div');
                        textDiv.className = 'message-text';
                        textDiv.textContent = msgData.text;
                        
                        bubble.appendChild(header);
                        bubble.appendChild(textDiv);
                        
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
                        
                        displayedMessageIds.add(msg.id);
                        lastMessageId = Math.max(lastMessageId, msg.id);
                    }
                    
                    if (shouldScroll) container.scrollTop = container.scrollHeight;
                }
            } catch (e) {
                console.error('Load messages error:', e);
            }
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
        });
    </script>
</body>
</html>