ALTER TABLE posts
  ADD COLUMN idea_regen_count INT NOT NULL DEFAULT 0 AFTER image_edit_count;
