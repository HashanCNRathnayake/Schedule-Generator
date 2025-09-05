CREATE TABLE courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id VARCHAR(64) NOT NULL,
  course_code VARCHAR(64),
  course_title_external VARCHAR(255),
  UNIQUE KEY (course_id)
);
