-- 11/18: "typing..." in chats. When each side last typed (cleared when they send). Optional: without it the chat
-- works the same, just without the typing indicator.
ALTER TABLE dm_conversations ADD COLUMN low_typing_at DATETIME NULL AFTER high_cleared_id;
ALTER TABLE dm_conversations ADD COLUMN high_typing_at DATETIME NULL AFTER low_typing_at;
