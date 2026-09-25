-- Organizer Dashboard — additive schema only (no drops, no destructive changes).
-- Run this against production via phpMyAdmin the same way migration 002 was run.

-- Lets an organizer pause/resume sales on a ticket tier without deleting it
-- (deleting is blocked once quantity_sold > 0 anyway — this is the real
-- "Pause Sales" / "Resume Sales" action from the Tickets page).
ALTER TABLE ticket_types ADD COLUMN sales_paused TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order;

-- Team members an organizer owner invites, with sub-roles enforced server-side
-- via ORGANIZER_PERMISSIONS in includes/organizer.php (mirrors the admin RBAC
-- pattern already in includes/admin.php).
CREATE TABLE organizer_team_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    user_id INT NULL,
    email VARCHAR(191) NOT NULL,
    name VARCHAR(191) NULL,
    role ENUM('EVENT_MANAGER','FINANCE_MANAGER','CHECKIN_STAFF','MARKETING_MANAGER') NOT NULL,
    status ENUM('INVITED','ACTIVE','REVOKED') NOT NULL DEFAULT 'INVITED',
    invited_by INT NOT NULL,
    invite_token VARCHAR(64) NULL,
    invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    joined_at DATETIME NULL,
    CONSTRAINT fk_otm_organizer FOREIGN KEY (organizer_id) REFERENCES users(id),
    CONSTRAINT fk_otm_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_otm_invited_by FOREIGN KEY (invited_by) REFERENCES users(id),
    UNIQUE KEY uniq_otm_org_email (organizer_id, email)
);

-- Generic per-user notification feed (organizer dashboard is the first consumer;
-- shape is intentionally generic so admin/customer sides could reuse it later).
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(60) NOT NULL,
    title VARCHAR(191) NOT NULL,
    body VARCHAR(500) NULL,
    link VARCHAR(255) NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE INDEX idx_notifications_user ON notifications(user_id, read_at);

-- Organizer-initiated refund requests — the organizer requests, an admin with
-- orders.refund still approves (calls the existing process_refund()), so
-- financial integrity/authorization stays exactly where it already lives.
CREATE TABLE refund_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    organizer_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status ENUM('REQUESTED','APPROVED','REJECTED','COMPLETED') NOT NULL DEFAULT 'REQUESTED',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    processed_by INT NULL,
    CONSTRAINT fk_rr_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_rr_organizer FOREIGN KEY (organizer_id) REFERENCES users(id),
    CONSTRAINT fk_rr_processed_by FOREIGN KEY (processed_by) REFERENCES users(id)
);

-- Organizer-side activity log — a distinct shape from admin's audit_logs
-- (owner + acting team member, rather than a single admin actor), so it's a
-- new table rather than overloading audit_logs' semantics.
CREATE TABLE organizer_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    actor_id INT NOT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(40) NULL,
    entity_id INT NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_oal_organizer FOREIGN KEY (organizer_id) REFERENCES users(id),
    CONSTRAINT fk_oal_actor FOREIGN KEY (actor_id) REFERENCES users(id)
);
CREATE INDEX idx_oal_organizer ON organizer_activity_log(organizer_id, created_at);

-- Reuse the existing admin contact inbox for organizer support tickets instead
-- of a duplicate table: an organizer's ticket is just a contact_messages row
-- with organizer_id set, so it shows up in the same admin-contact.php inbox.
ALTER TABLE contact_messages
    ADD COLUMN organizer_id INT NULL AFTER id,
    ADD COLUMN priority ENUM('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'MEDIUM' AFTER topic,
    ADD CONSTRAINT fk_contact_organizer FOREIGN KEY (organizer_id) REFERENCES users(id);
