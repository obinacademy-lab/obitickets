-- 11/18: members follow members (organizers keep organizer_follows, which the feed uses), and private messages
-- (one conversation per pair of people; strangers' first messages wait in Requests until accepted).
-- Blocking reuses user_blocks.

CREATE TABLE IF NOT EXISTS user_follows (
    follower_id INT NOT NULL,
    followee_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_id, followee_id),
    KEY idx_user_follows_followee (followee_id, created_at),
    CONSTRAINT fk_uf_follower FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uf_followee FOREIGN KEY (followee_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS dm_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_low INT NOT NULL,                 -- the smaller user id of the pair
    user_high INT NOT NULL,                -- the larger one
    started_by INT NOT NULL,
    accepted_at DATETIME NULL,             -- NULL = still a message request for the other person
    last_message_at DATETIME NULL,
    low_read_id BIGINT NOT NULL DEFAULT 0, -- last message id each side has seen
    high_read_id BIGINT NOT NULL DEFAULT 0,
    low_cleared_id BIGINT NOT NULL DEFAULT 0,  -- "delete chat" hides messages up to here for that side
    high_cleared_id BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dm_pair (user_low, user_high),
    KEY idx_dm_low (user_low, last_message_at),
    KEY idx_dm_high (user_high, last_message_at),
    CONSTRAINT fk_dm_low FOREIGN KEY (user_low) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_dm_high FOREIGN KEY (user_high) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS dm_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id INT NOT NULL,
    body VARCHAR(1000) NOT NULL DEFAULT '',
    event_id INT NULL,                     -- a shared event card
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dm_msg_conv (conversation_id, id),
    KEY idx_dm_msg_sender (sender_id, created_at),
    CONSTRAINT fk_dm_msg_conv FOREIGN KEY (conversation_id) REFERENCES dm_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_dm_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_dm_msg_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS dm_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    reporter_id INT NOT NULL,
    reported_id INT NOT NULL,
    reason VARCHAR(60) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dm_reports_status (status, created_at),
    CONSTRAINT fk_dmr_conv FOREIGN KEY (conversation_id) REFERENCES dm_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_dmr_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE
);
