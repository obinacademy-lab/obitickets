-- 043: questions at checkout (11/24). Organizers ask buyers things like T-shirt size, school, meal or "who invited you".
-- Answers are kept per order and show in the organizer's order page and attendee CSV. Before this runs nothing shows.
CREATE TABLE event_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  label VARCHAR(160) NOT NULL,
  kind ENUM('TEXT','CHOICE') NOT NULL DEFAULT 'TEXT',
  options VARCHAR(1000) NULL,               -- choices, one per line (CHOICE only)
  required TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,     -- removed questions are switched off so old answers keep their label
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_eq_event (event_id, active),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  question_id INT NOT NULL,
  answer VARCHAR(500) NOT NULL,
  UNIQUE KEY uq_order_question (order_id, question_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES event_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
