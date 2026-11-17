-- CampusFind normalized schema and fictional classroom demo records.
CREATE DATABASE IF NOT EXISTS campus_lost_found CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE campus_lost_found;

CREATE TABLE IF NOT EXISTS person (
  person_id INT AUTO_INCREMENT PRIMARY KEY,
  fname VARCHAR(50) NOT NULL,
  lname VARCHAR(50) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS student (
  student_id INT PRIMARY KEY,
  person_id INT NOT NULL UNIQUE,
  department VARCHAR(100) NOT NULL,
  year INT NOT NULL CHECK (year > 0),
  FOREIGN KEY (person_id) REFERENCES person(person_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS admin (
  admin_id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL UNIQUE,
  role VARCHAR(50) NOT NULL,
  FOREIGN KEY (person_id) REFERENCES person(person_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS item_category (
  category_id INT AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS item (
  item_id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  brand VARCHAR(50), model VARCHAR(50), color VARCHAR(30),
  serial_no VARCHAR(100) UNIQUE, features TEXT,
  FOREIGN KEY (category_id) REFERENCES item_category(category_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS location (
  location_id INT AUTO_INCREMENT PRIMARY KEY,
  building VARCHAR(100) NOT NULL, floor INT, area VARCHAR(100), description TEXT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS lost_report (
  lost_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL, item_id INT NOT NULL, location_id INT NOT NULL,
  lost_date DATETIME NOT NULL, description TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Matched','Resolved')),
  FOREIGN KEY (student_id) REFERENCES student(student_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (item_id) REFERENCES item(item_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (location_id) REFERENCES location(location_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX idx_lost_status (status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS found_report (
  found_id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL, item_id INT NOT NULL, location_id INT NOT NULL,
  found_date DATETIME NOT NULL, description TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'Unclaimed' CHECK (status IN ('Unclaimed','Claimed','Handed Over')),
  FOREIGN KEY (admin_id) REFERENCES admin(admin_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (item_id) REFERENCES item(item_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (location_id) REFERENCES location(location_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX idx_found_status (status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS item_match (
  lost_id INT NOT NULL, found_id INT NOT NULL,
  match_reason TEXT, match_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  match_status VARCHAR(20) NOT NULL DEFAULT 'Pending' CHECK (match_status IN ('Pending','Verified','Rejected')),
  PRIMARY KEY (lost_id, found_id),
  FOREIGN KEY (lost_id) REFERENCES lost_report(lost_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (found_id) REFERENCES found_report(found_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS claim (
  claim_id INT AUTO_INCREMENT PRIMARY KEY,
  lost_id INT NOT NULL, found_id INT NOT NULL,
  claim_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(20) NOT NULL DEFAULT 'Under Review' CHECK (status IN ('Under Review','Approved','Denied')),
  FOREIGN KEY (lost_id, found_id) REFERENCES item_match(lost_id, found_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX idx_claim_status (status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS claim_evidence (
  claim_id INT NOT NULL, evidence_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL, evidence_type VARCHAR(50), description TEXT,
  PRIMARY KEY (claim_id, evidence_id),
  FOREIGN KEY (claim_id) REFERENCES claim(claim_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS verification (
  verification_id INT AUTO_INCREMENT PRIMARY KEY,
  claim_id INT NOT NULL, admin_id INT NOT NULL, verification_date DATETIME NOT NULL,
  method VARCHAR(50), result VARCHAR(20) NOT NULL CHECK (result IN ('Pass','Fail')), remarks TEXT,
  FOREIGN KEY (claim_id) REFERENCES claim(claim_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (admin_id) REFERENCES admin(admin_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS handover (
  handover_id INT AUTO_INCREMENT PRIMARY KEY,
  verification_id INT NOT NULL, student_id INT NOT NULL, admin_id INT NOT NULL,
  handover_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, remarks TEXT,
  FOREIGN KEY (verification_id) REFERENCES verification(verification_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (student_id) REFERENCES student(student_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (admin_id) REFERENCES admin(admin_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Fictional demo rows make the site useful immediately after import.
INSERT IGNORE INTO person (person_id,fname,lname,email) VALUES
 (1,'Riya','Sharma','riya.demo@campus.edu'),(2,'Arjun','Mehta','arjun.demo@campus.edu'),(3,'Campus','Desk','desk.demo@campus.edu');
INSERT IGNORE INTO student (student_id,person_id,department,year) VALUES (20251501,1,'Computer Science',2),(20251502,2,'Design',3);
INSERT IGNORE INTO admin (admin_id,person_id,role) VALUES (1,3,'Lost and Found Desk');
INSERT IGNORE INTO item_category (category_id,category_name) VALUES (1,'Electronics'),(2,'Books and Notes'),(3,'Accessories'),(4,'Documents');
INSERT IGNORE INTO location (location_id,building,floor,area,description) VALUES
 (1,'Main Library',1,'Study Lounge','Near the long tables'),(2,'Student Centre',0,'North Entrance','Beside the notice board'),(3,'Engineering Block',2,'Lab Corridor','Outside the project lab');
INSERT IGNORE INTO item (item_id,category_id,brand,model,color,serial_no,features) VALUES
 (1,1,'Soundcore','Life P2','Black',NULL,'Small wireless earbud case with a short scratch on the lid'),
 (2,1,'Soundcore','Life P2','Black',NULL,'Black earbud case found near a study table'),
 (3,2,NULL,'Studio notebook','Blue',NULL,'Blue ruled notebook with a campus sticker');
INSERT IGNORE INTO lost_report (lost_id,student_id,item_id,location_id,lost_date,description,status) VALUES (1,20251501,1,1,'2026-11-15 13:40:00','Last seen after the afternoon study session.','Matched');
INSERT IGNORE INTO found_report (found_id,admin_id,item_id,location_id,found_date,description,status) VALUES
 (1,1,2,1,'2026-11-15 16:10:00','Handed in at the library desk.','Unclaimed'),(2,1,3,2,'2026-11-16 10:20:00','Left near the entrance.','Unclaimed');
INSERT IGNORE INTO item_match (lost_id,found_id,match_reason,match_status) VALUES (1,1,'Same category, color, model, and reported location.','Pending');
INSERT IGNORE INTO claim (claim_id,lost_id,found_id,claim_date,status) VALUES (1,1,1,'2026-11-16 09:30:00','Under Review');
