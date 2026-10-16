-- Additive and repeatable: no tables, records, volumes or existing triggers are removed.
USE attendance_phase1;
DELIMITER //
CREATE TRIGGER IF NOT EXISTS attendance_class_insert BEFORE INSERT ON attendance FOR EACH ROW
BEGIN
 IF (SELECT class_id FROM students WHERE id=NEW.student_id) <>
    (SELECT class_id FROM sessions WHERE id=NEW.session_id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Student and session must belong to the same class';
 END IF;
END//
CREATE TRIGGER IF NOT EXISTS attendance_class_update BEFORE UPDATE ON attendance FOR EACH ROW
BEGIN
 IF (SELECT class_id FROM students WHERE id=NEW.student_id) <>
    (SELECT class_id FROM sessions WHERE id=NEW.session_id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Student and session must belong to the same class';
 END IF;
END//
CREATE TRIGGER IF NOT EXISTS student_class_update BEFORE UPDATE ON students FOR EACH ROW
BEGIN
 IF NEW.class_id <> OLD.class_id AND EXISTS(SELECT 1 FROM attendance WHERE student_id=OLD.id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cannot transfer a student with attendance history';
 END IF;
END//
CREATE TRIGGER IF NOT EXISTS session_class_update BEFORE UPDATE ON sessions FOR EACH ROW
BEGIN
 IF NEW.class_id <> OLD.class_id AND EXISTS(SELECT 1 FROM attendance WHERE session_id=OLD.id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cannot transfer a session with an attendance roster';
 END IF;
END//
DELIMITER ;
