-- Remove the image entrypoint's broad schema grant, including its escaped name.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'attendance_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `attendance\_phase1`.* TO 'attendance_app'@'%';
