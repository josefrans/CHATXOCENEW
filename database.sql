CREATE DATABASE IF NOT EXISTS nexus_chat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nexus_chat;

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room VARCHAR(50) NOT NULL DEFAULT 'general',
    sender_id VARCHAR(50) NOT NULL,
    sender_name VARCHAR(100) NOT NULL,
    avatar_color VARCHAR(20) DEFAULT '#10b981',
    content TEXT,
    image_data LONGTEXT,
    audio_url VARCHAR(255) DEFAULT NULL,
    reactions JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_room_id (room, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Si ya tenías la tabla creada, ejecuta únicamente esto:
-- ALTER TABLE messages ADD COLUMN audio_url VARCHAR(255) DEFAULT NULL AFTER image_data;