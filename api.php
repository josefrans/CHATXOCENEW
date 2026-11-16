<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- CONFIGURACIÓN DE BASE DE DATOS ---
$host = 'localhost';
$db   = 'nexus_chat';
$user = 'root';      // Tu usuario de MySQL
$pass = '';          // Tu contraseña de MySQL
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la BD']);
    exit;
}

$action = $_GET['action'] ?? '';

// 1. OBTENER MENSAJES
if ($action === 'fetch') {
    $room = $_GET['room'] ?? 'general';
    $lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

    if ($lastId === 0) {
        $stmt = $pdo->prepare("SELECT * FROM (SELECT * FROM messages WHERE room = ? ORDER BY id DESC LIMIT 100) sub ORDER BY id ASC");
        $stmt->execute([$room]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM messages WHERE room = ? AND id > ? ORDER BY id ASC");
        $stmt->execute([$room, $lastId]);
    }

    $messages = $stmt->fetchAll();
    foreach ($messages as &$msg) {
        $msg['reactions'] = $msg['reactions'] ? json_decode($msg['reactions'], true) : new stdClass();
    }

    echo json_encode(['status' => 'ok', 'messages' => $messages]);
    exit;
}

// 2. ENVIAR MENSAJE (Texto, Imagen o Nota de Voz)
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    // Detectar si viene por FormData (con audio) o JSON (texto/imagen)
    if (strpos($contentType, 'multipart/form-data') !== false) {
        $room        = trim($_POST['room'] ?? 'general');
        $senderId    = trim($_POST['sender_id'] ?? 'anon');
        $senderName  = htmlspecialchars(trim($_POST['sender_name'] ?? 'Invitado'), ENT_QUOTES, 'UTF-8');
        $avatarColor = trim($_POST['avatar_color'] ?? '#10b981');
        $content     = trim($_POST['content'] ?? '');
        $imageData   = !empty($_POST['image_data']) ? $_POST['image_data'] : null;
    } else {
        $data        = json_decode(file_get_contents('php://input'), true) ?? [];
        $room        = trim($data['room'] ?? 'general');
        $senderId    = trim($data['sender_id'] ?? 'anon');
        $senderName  = htmlspecialchars(trim($data['sender_name'] ?? 'Invitado'), ENT_QUOTES, 'UTF-8');
        $avatarColor = trim($data['avatar_color'] ?? '#10b981');
        $content     = trim($data['content'] ?? '');
        $imageData   = $data['image_data'] ?? null;
    }

    $audioUrl = null;

    // Procesar archivo de nota de voz si se adjuntó
    if (isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/audio/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Límite de seguridad de 8 MB por nota de voz
        if ($_FILES['audio']['size'] > 8 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'El audio supera los 8MB permitidos']);
            exit;
        }

        $ext = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION) ?: 'webm';
        $allowedExt = ['webm', 'ogg', 'mp3', 'wav', 'm4a', 'mp4'];
        if (!in_array(strtolower($ext), $allowedExt)) {
            $ext = 'webm';
        }

        $fileName = 'voice_' . uniqid() . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['audio']['tmp_name'], $targetPath)) {
            $audioUrl = 'uploads/audio/' . $fileName;
        }
    }

    if (empty($content) && empty($imageData) && empty($audioUrl)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Mensaje vacío']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO messages (room, sender_id, sender_name, avatar_color, content, image_data, audio_url, reactions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$room, $senderId, $senderName, $avatarColor, $content, $imageData, $audioUrl, json_encode(new stdClass())]);

    echo json_encode(['status' => 'ok', 'id' => $pdo->lastInsertId()]);
    exit;
}

// 3. TOGGLE DE REACCIONES
if ($action === 'react' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $msgId = (int)($data['message_id'] ?? 0);
    $emoji = $data['emoji'] ?? '';
    $userId = $data['user_id'] ?? '';

    if ($msgId > 0 && !empty($emoji) && !empty($userId)) {
        $stmt = $pdo->prepare("SELECT reactions FROM messages WHERE id = ?");
        $stmt->execute([$msgId]);
        $row = $stmt->fetch();

        if ($row) {
            $reactions = $row['reactions'] ? json_decode($row['reactions'], true) : [];
            if (!isset($reactions[$emoji])) {
                $reactions[$emoji] = [];
            }

            if (in_array($userId, $reactions[$emoji])) {
                $reactions[$emoji] = array_values(array_diff($reactions[$emoji], [$userId]));
                if (empty($reactions[$emoji])) unset($reactions[$emoji]);
            } else {
                $reactions[$emoji][] = $userId;
            }

            $update = $pdo->prepare("UPDATE messages SET reactions = ? WHERE id = ?");
            $update->execute([json_encode((object)$reactions), $msgId]);

            echo json_encode(['status' => 'ok', 'reactions' => (object)$reactions]);
            exit;
        }
    }
    echo json_encode(['status' => 'error']);
    exit;
}