-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: dental_ai_system
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `admin_notifications`
--

DROP TABLE IF EXISTS `admin_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) DEFAULT NULL,
  `concern_id` int(11) DEFAULT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'Appointment Concern',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `email_sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_appointment_id` (`appointment_id`),
  KEY `idx_concern_id` (`concern_id`),
  KEY `idx_patient_id` (`patient_id`),
  KEY `idx_is_read` (`is_read`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_notifications`
--

LOCK TABLES `admin_notifications` WRITE;
/*!40000 ALTER TABLE `admin_notifications` DISABLE KEYS */;
INSERT INTO `admin_notifications` VALUES (1,3,1,2,'New Appointment Concern','Michaela Pacamana submitted an appointment concern for September 21, 2026 at 02:00 PM. Reason: Emergency','Appointment Concern',1,'2026-09-15 17:50:26',NULL),(2,10,2,2,'New Appointment Concern','Michaela Pacamana submitted an appointment concern for September 25, 2026 at 09:00 AM. Reason: Email Request','Appointment Concern',1,'2026-09-21 10:00:10',NULL),(3,11,3,1,'New Appointment Concern','Rocel Tagudar submitted an appointment concern for September 24, 2026 at 01:00 PM. Reason: Email Request','Appointment Concern',1,'2026-09-21 10:37:19','2026-10-06 13:15:11'),(4,55,4,14,'New Appointment Concern','Juan Dela Cruz submitted an appointment concern (October 06, 2026 at 10:00 AM). Reason: General Inquiry. Message: Hello, will this procedure require fasting or any special preparation beforehand?','Appointment Concern',1,'2026-10-03 11:02:04',NULL),(20,21,8,1,'New Appointment Concern','Rocel Tagudar submitted an appointment concern (October 14, 2026 at 09:00 AM). Reason: Personal / Family Matter. Message: May kinakailangan lang ho kaming lakarin, its family matter thank you for your understanding','Appointment Concern',1,'2026-10-09 10:21:52',NULL);
/*!40000 ALTER TABLE `admin_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_concerns`
--

DROP TABLE IF EXISTS `appointment_concerns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointment_concerns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) DEFAULT NULL,
  `patient_id` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `status` enum('New','Reviewed') NOT NULL DEFAULT 'New',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_appointment_id` (`appointment_id`),
  KEY `idx_patient_id` (`patient_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_concerns`
--

LOCK TABLES `appointment_concerns` WRITE;
/*!40000 ALTER TABLE `appointment_concerns` DISABLE KEYS */;
INSERT INTO `appointment_concerns` VALUES (1,3,2,'Emergency','Unable to attend my appointment on 2026-09-21 due to emergency - please reschedule. Kindly notify admin.','Reviewed','2026-09-15 17:50:26'),(2,10,2,'Email Request','Patient emailed admin about reschedule via dashboard (appointment #10).','Reviewed','2026-09-21 09:42:19'),(3,11,1,'Email Request','Patient emailed admin about reschedule via My Appointments (appointment #11).','Reviewed','2026-09-21 10:21:31'),(4,55,14,'General Inquiry','Hello, will this procedure require fasting or any special preparation beforehand?','Reviewed','2026-10-03 11:02:04'),(5,88,18,'Email Request','Patient emailed admin about reschedule via My Appointments (appointment #88).','New','2026-10-03 11:15:31'),(6,89,29,'Email Request','Patient emailed admin about reschedule via My Appointments (appointment #89).','New','2026-10-03 11:59:11'),(7,114,36,'Confirmation','Patient confirmed attendance via My Appointments for appointment on 2026-10-13 at 12:00 PM.','New','2026-10-04 15:34:49'),(8,21,1,'Personal / Family Matter','May kinakailangan lang ho kaming lakarin, its family matter thank you for your understanding','Reviewed','2026-10-09 10:21:52');
/*!40000 ALTER TABLE `appointment_concerns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `dentist_id` int(11) NOT NULL,
  `appointment_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `service_type` varchar(150) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Pending',
  `notes` text DEFAULT NULL,
  `reply_token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `confirmed_at` datetime DEFAULT NULL,
  `checked_in_at` datetime DEFAULT NULL,
  `serving_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `rescheduled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `dentist_id` (`dentist_id`),
  CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`dentist_id`) REFERENCES `dentists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=232 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
INSERT INTO `appointments` VALUES (1,1,3,'2026-09-18','09:00:00','10:00:00','Dental Check-up','Cancelled','Cancelled by patient: I have a family emergency',NULL,'2026-09-15 16:53:29',NULL,NULL,NULL,NULL,NULL,NULL),(2,2,1,'2026-09-18','09:00:00','10:00:00','Oral Prophylaxis or Cleaning','Cancelled','Please confirm via Appointment Concern if unable to attend - Oral Prophylaxis scheduled\nCancelled by patient: family emergency',NULL,'2026-09-15 17:38:39',NULL,NULL,NULL,NULL,NULL,NULL),(3,2,1,'2026-09-21','14:00:00','14:30:00','Dental Check-up','Completed','Concern: Emergency - body needed (via Appointment Concern)',NULL,'2026-09-15 17:38:39',NULL,NULL,NULL,NULL,NULL,NULL),(4,2,1,'2026-09-11','10:00:00','11:00:00','Fillings or Pasta','Completed','Treatment completed - Follow-up in 6 months',NULL,'2026-09-15 17:38:47',NULL,NULL,NULL,NULL,NULL,NULL),(5,2,1,'2026-09-18','10:00:00','11:30:00','Crown','Completed','',NULL,'2026-09-15 18:11:10',NULL,NULL,NULL,NULL,NULL,NULL),(6,1,3,'2026-09-19','10:00:00','11:00:00','Fillings or Pasta','Completed','Cancelled by patient (no reason given).',NULL,'2026-09-18 07:42:14',NULL,NULL,NULL,NULL,NULL,NULL),(7,1,2,'2026-09-21','13:30:00','15:00:00','Bridge','No-Show','Cancelled by patient: i have family emergency',NULL,'2026-09-18 07:55:03',NULL,NULL,NULL,NULL,NULL,NULL),(8,1,3,'2026-09-19','14:00:00','15:30:00','Bridge','Completed','',NULL,'2026-09-18 09:19:34',NULL,NULL,NULL,NULL,NULL,NULL),(9,1,4,'2026-09-21','11:00:00','12:30:00','Bridge','Completed','',NULL,'2026-09-18 09:39:03',NULL,NULL,NULL,NULL,NULL,NULL),(10,2,4,'2026-09-25','09:00:00','10:00:00','Denture','Completed','',NULL,'2026-09-21 09:35:19',NULL,NULL,NULL,NULL,NULL,NULL),(11,1,1,'2026-09-24','13:00:00','15:00:00','Cosmetic','Completed','',NULL,'2026-09-21 10:21:21',NULL,NULL,NULL,NULL,NULL,NULL),(12,2,1,'2026-10-20','12:00:00','13:00:00','Denture','Pending','',NULL,'2026-10-03 07:02:06',NULL,NULL,NULL,NULL,NULL,NULL),(13,9,1,'2026-10-05','14:00:00','15:00:00','Oral Prophylaxis or Cleaning','Completed','',NULL,'2026-10-03 07:06:37',NULL,NULL,NULL,NULL,NULL,NULL),(14,9,1,'2026-10-14','13:00:00','15:00:00','Root Canal','Pending','',NULL,'2026-10-03 07:07:09',NULL,NULL,NULL,NULL,NULL,NULL),(15,10,1,'2026-10-05','08:00:00','09:30:00','Teeth Whitening','Completed','',NULL,'2026-10-03 07:11:47',NULL,NULL,NULL,NULL,NULL,NULL),(16,10,1,'2026-10-07','11:00:00','12:30:00','Bridge','Completed','',NULL,'2026-10-03 07:13:51',NULL,NULL,NULL,NULL,NULL,NULL),(17,11,1,'2026-10-06','12:00:00','14:00:00','Veneers','Completed','gusto ko pumuti ipin ko',NULL,'2026-10-03 07:17:54',NULL,NULL,NULL,NULL,NULL,NULL),(18,11,1,'2026-10-07','14:00:00','15:30:00','Oral Surgery (Tooth Extraction or Bunot)','Completed','so hurt',NULL,'2026-10-03 07:18:37',NULL,NULL,NULL,NULL,NULL,NULL),(19,2,1,'2026-10-21','10:00:00','11:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-03 07:19:16',NULL,NULL,NULL,NULL,NULL,NULL),(20,2,1,'2026-10-16','15:00:00','16:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-03 07:20:31',NULL,NULL,NULL,NULL,NULL,NULL),(21,1,1,'2026-10-14','09:00:00','10:00:00','Denture','Pending','',NULL,'2026-10-03 07:49:08',NULL,NULL,NULL,NULL,NULL,NULL),(22,1,1,'2026-10-16','08:00:00','09:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-03 07:49:25',NULL,NULL,NULL,NULL,NULL,NULL),(23,1,1,'2026-10-19','13:00:00','14:00:00','Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-03 07:49:41',NULL,NULL,NULL,NULL,NULL,NULL),(24,1,1,'2026-10-22','15:00:00','16:30:00','Teeth Whitening','Pending','',NULL,'2026-10-03 07:50:14',NULL,NULL,NULL,NULL,NULL,NULL),(25,1,1,'2026-10-27','16:00:00','17:00:00','General Dentistry','Pending','',NULL,'2026-10-03 07:51:05',NULL,NULL,NULL,NULL,NULL,NULL),(26,2,1,'2026-10-22','13:00:00','14:00:00','Denture','Pending','',NULL,'2026-10-03 07:52:13',NULL,NULL,NULL,NULL,NULL,NULL),(27,2,1,'2026-10-23','11:00:00','13:00:00','Cosmetic','Pending','',NULL,'2026-10-03 07:52:39',NULL,NULL,NULL,NULL,NULL,NULL),(28,10,1,'2026-10-22','10:00:00','11:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-03 07:53:55',NULL,NULL,NULL,NULL,NULL,NULL),(29,10,1,'2026-10-27','17:00:00','18:00:00','General Dentistry','Pending','',NULL,'2026-10-03 07:54:43',NULL,NULL,NULL,NULL,NULL,NULL),(30,11,1,'2026-10-09','09:00:00','10:00:00','Fillings or Pasta','Completed','',NULL,'2026-10-03 07:56:21','2026-10-08 20:44:28',NULL,NULL,NULL,NULL,NULL),(31,11,1,'2026-10-12','12:00:00','14:00:00','Orthodontics (Braces) + Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-03 07:56:52',NULL,NULL,NULL,NULL,NULL,NULL),(32,9,1,'2026-10-06','14:00:00','15:00:00','General Dentistry','Completed','',NULL,'2026-10-03 07:58:21',NULL,NULL,NULL,NULL,NULL,NULL),(33,9,1,'2026-10-09','11:00:00','12:30:00','Bridge','Completed','',NULL,'2026-10-03 07:58:42',NULL,NULL,NULL,NULL,NULL,NULL),(34,12,1,'2026-10-05','15:50:00','18:50:00','Root Canal + Oral Prophylaxis or Cleaning','Completed','Cleaning',NULL,'2026-10-03 10:42:32',NULL,NULL,NULL,NULL,NULL,NULL),(35,11,1,'2026-10-14','08:00:00','09:00:00','Denture','Pending','',NULL,'2026-10-03 10:45:13',NULL,NULL,NULL,NULL,NULL,NULL),(36,11,1,'2026-10-06','18:45:00','19:45:00','General Dentistry','Completed','',NULL,'2026-10-03 10:45:47',NULL,NULL,NULL,NULL,NULL,NULL),(37,11,1,'2026-10-15','14:00:00','16:00:00','Veneers','Pending','',NULL,'2026-10-03 10:46:07',NULL,NULL,NULL,NULL,NULL,NULL),(38,11,1,'2026-10-16','13:00:00','15:00:00','Veneers','Pending','',NULL,'2026-10-03 10:46:33',NULL,NULL,NULL,NULL,NULL,NULL),(39,11,1,'2026-10-16','16:00:00','18:00:00','Veneers','Pending','',NULL,'2026-10-03 10:46:50',NULL,NULL,NULL,NULL,NULL,NULL),(40,11,1,'2026-10-08','18:46:00','19:46:00','Oral Prophylaxis or Cleaning','Completed','',NULL,'2026-10-03 10:47:14',NULL,NULL,NULL,NULL,NULL,NULL),(41,11,1,'2026-10-19','08:00:00','10:00:00','Veneers','Pending','',NULL,'2026-10-03 10:47:52',NULL,NULL,NULL,NULL,NULL,NULL),(42,11,1,'2026-10-15','18:48:00','20:18:00','Bridge','Pending','',NULL,'2026-10-03 10:48:13',NULL,NULL,NULL,NULL,NULL,NULL),(43,11,1,'2026-10-14','18:48:00','20:48:00','Veneers','Pending','',NULL,'2026-10-03 10:48:39',NULL,NULL,NULL,NULL,NULL,NULL),(44,11,1,'2026-10-20','15:48:00','17:18:00','Bridge','Pending','',NULL,'2026-10-03 10:49:00',NULL,NULL,NULL,NULL,NULL,NULL),(45,11,1,'2026-10-21','08:00:00','10:00:00','Root Canal','Pending','',NULL,'2026-10-03 10:49:30',NULL,NULL,NULL,NULL,NULL,NULL),(46,11,1,'2026-10-20','18:49:00','20:19:00','Teeth Whitening','Pending','',NULL,'2026-10-03 10:49:46',NULL,NULL,NULL,NULL,NULL,NULL),(47,11,1,'2026-10-21','11:00:00','12:00:00','Denture','Pending','',NULL,'2026-10-03 10:50:07',NULL,NULL,NULL,NULL,NULL,NULL),(48,11,1,'2026-10-20','20:50:00','21:50:00','Denture','Pending','',NULL,'2026-10-03 10:50:30',NULL,NULL,NULL,NULL,NULL,NULL),(49,11,1,'2026-10-21','12:00:00','13:30:00','Crown','Pending','',NULL,'2026-10-03 10:50:50',NULL,NULL,NULL,NULL,NULL,NULL),(50,11,1,'2026-10-22','08:00:00','09:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-03 10:51:04',NULL,NULL,NULL,NULL,NULL,NULL),(51,11,1,'2026-10-22','09:00:00','10:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-03 10:51:32',NULL,NULL,NULL,NULL,NULL,NULL),(52,11,1,'2026-10-22','12:00:00','13:00:00','Denture','Pending','',NULL,'2026-10-03 10:51:48',NULL,NULL,NULL,NULL,NULL,NULL),(53,11,1,'2026-10-22','14:00:00','15:00:00','General Dentistry','Pending','',NULL,'2026-10-03 10:52:09',NULL,NULL,NULL,NULL,NULL,NULL),(54,11,1,'2026-10-23','17:00:00','18:00:00','Denture','Pending','',NULL,'2026-10-03 10:52:22',NULL,NULL,NULL,NULL,NULL,NULL),(55,14,4,'2026-10-06','10:00:00','11:00:00','Oral Prophylaxis or Cleaning','Completed','Routine cleaning and dental checkup test appointment',NULL,'2026-10-03 11:00:44','2026-10-05 16:56:50',NULL,NULL,NULL,NULL,NULL),(56,14,4,'2026-10-08','09:00:00','10:00:00','General Dentistry','No-Show','Automated test appointment 1',NULL,'2026-10-03 11:07:15',NULL,NULL,NULL,NULL,NULL,NULL),(57,14,1,'2026-10-12','09:00:00','10:00:00','General Dentistry','Confirmed','Batch entry 1',NULL,'2026-10-03 11:07:59','2026-10-10 13:16:44',NULL,NULL,NULL,NULL,NULL),(58,14,1,'2026-10-12','10:30:00','11:30:00','Oral Prophylaxis or Cleaning','Pending','Batch entry 2',NULL,'2026-10-03 11:08:00',NULL,NULL,NULL,NULL,NULL,NULL),(59,14,1,'2026-10-12','14:30:00','15:30:00','General Dentistry','Pending','Batch entry 4',NULL,'2026-10-03 11:08:01',NULL,NULL,NULL,NULL,NULL,NULL),(60,14,1,'2026-10-13','09:00:00','10:00:00','Oral Prophylaxis or Cleaning','Pending','Batch entry 5',NULL,'2026-10-03 11:08:01',NULL,NULL,NULL,NULL,NULL,NULL),(61,14,2,'2026-10-13','10:00:00','11:00:00','Orthodontics (Braces)','Pending','Batch entry 6',NULL,'2026-10-03 11:08:02',NULL,NULL,NULL,NULL,NULL,NULL),(62,14,2,'2026-10-13','11:30:00','12:30:00','General Dentistry','Pending','Batch entry 7',NULL,'2026-10-03 11:08:02',NULL,NULL,NULL,NULL,NULL,NULL),(63,14,2,'2026-10-14','09:00:00','10:00:00','Oral Prophylaxis or Cleaning','Pending','Batch entry 8',NULL,'2026-10-03 11:08:03',NULL,NULL,NULL,NULL,NULL,NULL),(64,14,2,'2026-10-14','10:30:00','11:30:00','Fillings or Pasta','Pending','Batch entry 9',NULL,'2026-10-03 11:08:03',NULL,NULL,NULL,NULL,NULL,NULL),(65,14,2,'2026-10-14','14:00:00','15:00:00','Orthodontics (Braces)','Pending','Batch entry 10',NULL,'2026-10-03 11:08:04',NULL,NULL,NULL,NULL,NULL,NULL),(66,14,3,'2026-10-12','11:00:00','12:00:00','General Dentistry','Pending','Batch entry 11',NULL,'2026-10-03 11:08:04',NULL,NULL,NULL,NULL,NULL,NULL),(67,14,3,'2026-10-13','13:00:00','14:00:00','Oral Prophylaxis or Cleaning','Pending','Batch entry 12',NULL,'2026-10-03 11:08:04',NULL,NULL,NULL,NULL,NULL,NULL),(68,14,3,'2026-10-13','14:30:00','15:30:00','Fillings or Pasta','Pending','Batch entry 13',NULL,'2026-10-03 11:08:05',NULL,NULL,NULL,NULL,NULL,NULL),(69,14,3,'2026-10-14','11:00:00','12:00:00','General Dentistry','Pending','Batch entry 14',NULL,'2026-10-03 11:08:05',NULL,NULL,NULL,NULL,NULL,NULL),(70,14,3,'2026-10-16','09:30:00','10:30:00','Oral Prophylaxis or Cleaning','Pending','Batch entry 15',NULL,'2026-10-03 11:08:06',NULL,NULL,NULL,NULL,NULL,NULL),(71,14,4,'2026-10-12','15:00:00','16:00:00','General Dentistry','Pending','Batch entry 16',NULL,'2026-10-03 11:08:06',NULL,NULL,NULL,NULL,NULL,NULL),(72,14,4,'2026-10-13','15:30:00','16:30:00','Orthodontics (Braces)','Pending','Batch entry 17',NULL,'2026-10-03 11:08:09',NULL,NULL,NULL,NULL,NULL,NULL),(73,14,4,'2026-10-15','10:00:00','11:00:00','Oral Prophylaxis or Cleaning','Pending','Batch entry 18',NULL,'2026-10-03 11:08:10',NULL,NULL,NULL,NULL,NULL,NULL),(74,14,4,'2026-10-15','11:30:00','12:30:00','Fillings or Pasta','Pending','Batch entry 19',NULL,'2026-10-03 11:08:10',NULL,NULL,NULL,NULL,NULL,NULL),(75,14,4,'2026-10-16','14:00:00','15:00:00','General Dentistry','Pending','Batch entry 20',NULL,'2026-10-03 11:08:11',NULL,NULL,NULL,NULL,NULL,NULL),(76,17,4,'2026-10-05','09:12:00','10:12:00','General Dentistry','Completed','Discount please',NULL,'2026-10-03 11:13:18',NULL,NULL,NULL,NULL,NULL,NULL),(77,16,2,'2026-10-20','10:00:00','11:00:00','General Dentistry','Pending','Multi-account test 1',NULL,'2026-10-03 11:13:38',NULL,NULL,NULL,NULL,NULL,NULL),(78,19,1,'2026-10-19','10:00:00','11:00:00','General Dentistry','Pending','First appointment for Carlos',NULL,'2026-10-03 11:14:29',NULL,NULL,NULL,NULL,NULL,NULL),(79,20,1,'2026-10-19','11:30:00','12:30:00','Oral Prophylaxis or Cleaning','Pending','Routine dental prophylaxis',NULL,'2026-10-03 11:14:31',NULL,NULL,NULL,NULL,NULL,NULL),(80,21,2,'2026-10-20','11:30:00','12:30:00','Fillings or Pasta','Pending','Dental filling procedure',NULL,'2026-10-03 11:14:33',NULL,NULL,NULL,NULL,NULL,NULL),(81,22,2,'2026-10-20','13:30:00','14:30:00','General Dentistry','Pending','Annual checkup',NULL,'2026-10-03 11:14:34',NULL,NULL,NULL,NULL,NULL,NULL),(82,23,3,'2026-10-21','09:30:00','10:30:00','Oral Prophylaxis or Cleaning','Pending','Teeth cleaning session',NULL,'2026-10-03 11:14:36',NULL,NULL,NULL,NULL,NULL,NULL),(83,24,3,'2026-10-21','11:00:00','12:00:00','General Dentistry','Pending','General consultation',NULL,'2026-10-03 11:14:38',NULL,NULL,NULL,NULL,NULL,NULL),(84,25,4,'2026-10-22','09:00:00','10:00:00','Orthodontics (Braces)','Pending','Braces adjustment consultation',NULL,'2026-10-03 11:14:40',NULL,NULL,NULL,NULL,NULL,NULL),(85,26,4,'2026-10-22','10:30:00','11:30:00','Fillings or Pasta','Pending','Tooth filling check',NULL,'2026-10-03 11:14:45',NULL,NULL,NULL,NULL,NULL,NULL),(86,27,1,'2026-10-23','09:00:00','10:00:00','Oral Prophylaxis or Cleaning','Pending','Cleaning and polish',NULL,'2026-10-03 11:14:47',NULL,NULL,NULL,NULL,NULL,NULL),(87,28,2,'2026-10-23','10:30:00','11:30:00','General Dentistry','Pending','Dental evaluation',NULL,'2026-10-03 11:14:50',NULL,NULL,NULL,NULL,NULL,NULL),(88,18,2,'2026-10-06','13:30:00','14:30:00','General Dentistry','Completed','',NULL,'2026-10-03 11:15:15',NULL,NULL,NULL,NULL,NULL,NULL),(89,29,4,'2026-10-05','05:30:00','07:30:00','Root Canal','Completed','Trip ko lang',NULL,'2026-10-03 11:59:01',NULL,NULL,NULL,NULL,NULL,NULL),(90,30,1,'2026-10-09','13:00:00','14:00:00','Fillings or Pasta','Checked In','',NULL,'2026-10-04 14:23:22','2026-10-09 13:32:02','2026-10-09 13:32:11',NULL,NULL,NULL,NULL),(91,30,1,'2026-10-13','15:00:00','16:30:00','Bridge','Pending','',NULL,'2026-10-04 14:24:18',NULL,NULL,NULL,NULL,NULL,NULL),(92,30,1,'2026-10-16','09:00:00','10:00:00','Denture','Pending','',NULL,'2026-10-04 14:24:44',NULL,NULL,NULL,NULL,NULL,NULL),(93,31,1,'2026-10-14','10:00:00','11:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-04 14:39:28',NULL,NULL,NULL,NULL,NULL,NULL),(94,31,1,'2026-10-14','15:00:00','16:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-04 14:40:15',NULL,NULL,NULL,NULL,NULL,NULL),(95,32,3,'2026-10-07','08:00:00','09:30:00','Oral Surgery (Tooth Extraction or Bunot)','Completed','',NULL,'2026-10-04 15:06:58','2026-10-06 19:58:54',NULL,NULL,NULL,NULL,NULL),(96,32,4,'2026-10-08','13:00:00','14:00:00','General Dentistry','Completed','',NULL,'2026-10-04 15:07:20',NULL,NULL,NULL,NULL,NULL,NULL),(97,32,3,'2026-10-09','10:00:00','11:00:00','General Dentistry','Completed','',NULL,'2026-10-04 15:07:33','2026-10-08 20:44:42',NULL,NULL,NULL,NULL,NULL),(98,33,4,'2026-10-12','12:00:00','13:30:00','Teeth Whitening','Pending','',NULL,'2026-10-04 15:09:26',NULL,NULL,NULL,NULL,NULL,NULL),(99,33,1,'2026-10-16','11:00:00','12:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-04 15:10:55',NULL,NULL,NULL,NULL,NULL,NULL),(100,33,4,'2026-11-02','08:00:00','09:30:00','Bridge','Pending','',NULL,'2026-10-04 15:11:14',NULL,NULL,NULL,NULL,NULL,NULL),(101,34,1,'2026-11-02','11:00:00','12:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-04 15:13:33',NULL,NULL,NULL,NULL,NULL,NULL),(102,34,3,'2026-10-06','12:00:00','14:00:00','Root Canal','Completed','',NULL,'2026-10-04 15:13:43',NULL,NULL,NULL,NULL,NULL,NULL),(103,34,1,'2026-10-08','15:00:00','16:00:00','Orthodontics (Braces)','Completed','',NULL,'2026-10-04 15:13:54',NULL,NULL,NULL,NULL,NULL,NULL),(104,34,1,'2026-10-07','09:00:00','11:00:00','Root Canal','Completed','',NULL,'2026-10-04 15:14:11',NULL,NULL,NULL,NULL,NULL,NULL),(105,35,1,'2026-10-30','14:00:00','15:00:00','Denture','Pending','',NULL,'2026-10-04 15:15:53',NULL,NULL,NULL,NULL,NULL,NULL),(106,35,1,'2026-10-29','14:00:00','15:30:00','Teeth Whitening','Pending','',NULL,'2026-10-04 15:16:08',NULL,NULL,NULL,NULL,NULL,NULL),(107,35,4,'2026-10-27','13:00:00','14:00:00','Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-04 15:16:49',NULL,NULL,NULL,NULL,NULL,NULL),(108,37,1,'2026-11-02','12:00:00','13:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-04 15:19:41',NULL,NULL,NULL,NULL,NULL,NULL),(109,37,3,'2026-11-11','10:00:00','11:30:00','Teeth Whitening','Pending','',NULL,'2026-10-04 15:19:58',NULL,NULL,NULL,NULL,NULL,NULL),(110,37,3,'2026-10-16','13:00:00','15:00:00','Veneers','Pending','',NULL,'2026-10-04 15:20:08',NULL,NULL,NULL,NULL,NULL,NULL),(111,38,4,'2026-11-16','15:00:00','16:30:00','Teeth Whitening','Pending','',NULL,'2026-10-04 15:22:20',NULL,NULL,NULL,NULL,NULL,NULL),(112,38,3,'2026-11-25','12:00:00','13:00:00','Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-04 15:22:35',NULL,NULL,NULL,NULL,NULL,NULL),(113,38,3,'2026-11-20','09:00:00','10:00:00','General Dentistry','Pending','',NULL,'2026-10-04 15:23:01',NULL,NULL,NULL,NULL,NULL,NULL),(114,36,3,'2026-10-13','12:00:00','13:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-04 15:34:21',NULL,NULL,NULL,NULL,NULL,NULL),(115,36,3,'2026-10-19','10:00:00','11:00:00','Denture','Pending','',NULL,'2026-10-04 15:36:15',NULL,NULL,NULL,NULL,NULL,NULL),(116,39,4,'2026-10-10','09:00:00','10:00:00','General Dentistry','Completed','',NULL,'2026-10-09 08:41:18',NULL,NULL,NULL,NULL,NULL,NULL),(117,40,4,'2026-10-10','10:00:00','11:00:00','Fillings or Pasta','Completed','',NULL,'2026-10-09 08:41:46',NULL,NULL,NULL,NULL,NULL,NULL),(118,39,4,'2026-10-10','16:41:00','17:41:00','General Dentistry','Completed','',NULL,'2026-10-09 08:41:47','2026-10-10 13:14:39',NULL,NULL,NULL,NULL,NULL),(119,40,4,'2026-10-12','10:00:00','11:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-09 08:43:16',NULL,NULL,NULL,NULL,NULL,NULL),(120,41,4,'2026-10-17','09:00:00','10:00:00','Denture','Pending','',NULL,'2026-10-09 08:52:41',NULL,NULL,NULL,NULL,NULL,NULL),(121,42,4,'2026-10-15','14:00:00','15:30:00','Teeth Whitening','Pending','',NULL,'2026-10-09 08:55:28',NULL,NULL,NULL,NULL,NULL,NULL),(122,44,4,'2026-10-10','08:00:00','09:00:00','Denture','Completed','',NULL,'2026-10-09 11:58:36',NULL,NULL,NULL,NULL,NULL,NULL),(123,44,4,'2026-10-10','15:00:00','16:00:00','General Dentistry','Completed','',NULL,'2026-10-09 11:58:55','2026-10-10 13:14:35',NULL,NULL,NULL,NULL,NULL),(124,44,2,'2026-10-10','13:00:00','15:00:00','Veneers','Checked In','',NULL,'2026-10-09 11:59:16','2026-10-10 13:14:29','2026-10-10 13:16:25',NULL,NULL,NULL,NULL),(125,46,4,'2026-10-10','12:00:00','13:30:00','Crown','Completed','',NULL,'2026-10-09 12:00:45',NULL,NULL,NULL,NULL,NULL,NULL),(126,46,3,'2026-10-10','09:00:00','10:00:00','General Dentistry','No-Show','',NULL,'2026-10-09 12:01:04',NULL,NULL,NULL,NULL,NULL,NULL),(127,46,4,'2026-10-12','08:00:00','09:00:00','General Dentistry','Confirmed','',NULL,'2026-10-09 12:01:39','2026-10-10 13:16:40',NULL,NULL,NULL,NULL,NULL),(128,45,3,'2026-10-12','08:00:00','09:00:00','General Dentistry','Confirmed','',NULL,'2026-10-09 12:02:11','2026-10-10 13:15:20',NULL,NULL,NULL,NULL,NULL),(129,45,3,'2026-10-13','09:00:00','10:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-09 12:02:53',NULL,NULL,NULL,NULL,NULL,NULL),(130,47,2,'2026-10-10','17:00:00','18:00:00','General Dentistry','Completed','',NULL,'2026-10-09 12:02:57','2026-10-10 13:14:52',NULL,NULL,NULL,NULL,NULL),(131,47,2,'2026-10-10','15:00:00','16:00:00','General Dentistry','Completed','',NULL,'2026-10-09 12:03:12','2026-10-10 13:14:32',NULL,NULL,NULL,NULL,NULL),(132,47,1,'2026-10-13','13:00:00','14:00:00','Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-09 12:03:58',NULL,NULL,NULL,NULL,NULL,NULL),(133,48,4,'2026-10-15','16:00:00','18:00:00','Root Canal','Pending','',NULL,'2026-10-09 12:05:38',NULL,NULL,NULL,NULL,NULL,NULL),(134,48,1,'2026-10-20','09:00:00','10:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 12:05:55',NULL,NULL,NULL,NULL,NULL,NULL),(135,48,4,'2026-10-30','16:00:00','17:00:00','Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-09 12:06:04',NULL,NULL,NULL,NULL,NULL,NULL),(136,49,1,'2026-10-21','15:00:00','16:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 12:07:10',NULL,NULL,NULL,NULL,NULL,NULL),(137,49,4,'2026-10-31','12:00:00','13:00:00','Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-09 12:07:19',NULL,NULL,NULL,NULL,NULL,NULL),(138,49,3,'2026-10-31','14:00:00','15:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 12:07:32',NULL,NULL,NULL,NULL,NULL,NULL),(139,49,1,'2026-10-28','09:00:00','11:00:00','Root Canal','Pending','',NULL,'2026-10-09 12:07:43',NULL,NULL,NULL,NULL,NULL,NULL),(140,50,1,'2026-10-26','16:00:00','17:30:00','Crown','Pending','',NULL,'2026-10-09 12:07:51',NULL,NULL,NULL,NULL,NULL,NULL),(141,50,1,'2026-10-29','17:00:00','18:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:08:20',NULL,NULL,NULL,NULL,NULL,NULL),(142,51,4,'2026-10-26','12:00:00','13:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:08:40',NULL,NULL,NULL,NULL,NULL,NULL),(143,51,4,'2026-10-27','09:00:00','10:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:08:49',NULL,NULL,NULL,NULL,NULL,NULL),(144,51,1,'2026-10-28','12:00:00','14:00:00','Root Canal','Pending','',NULL,'2026-10-09 12:08:58',NULL,NULL,NULL,NULL,NULL,NULL),(145,50,1,'2026-10-29','13:00:00','14:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:09:05',NULL,NULL,NULL,NULL,NULL,NULL),(146,51,4,'2026-10-29','13:00:00','14:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:09:15',NULL,NULL,NULL,NULL,NULL,NULL),(147,52,4,'2026-10-30','12:00:00','14:00:00','Root Canal','Pending','',NULL,'2026-10-09 12:10:17',NULL,NULL,NULL,NULL,NULL,NULL),(148,52,1,'2026-10-29','09:00:00','10:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 12:10:54',NULL,NULL,NULL,NULL,NULL,NULL),(149,52,3,'2026-10-31','16:00:00','18:00:00','Root Canal','Pending','',NULL,'2026-10-09 12:11:04',NULL,NULL,NULL,NULL,NULL,NULL),(150,53,1,'2026-10-30','09:00:00','11:00:00','Veneers','Pending','',NULL,'2026-10-09 12:12:43',NULL,NULL,NULL,NULL,NULL,NULL),(151,53,1,'2026-10-23','10:00:00','11:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:12:53',NULL,NULL,NULL,NULL,NULL,NULL),(152,53,4,'2026-10-24','09:00:00','11:00:00','Root Canal','Pending','',NULL,'2026-10-09 12:13:02',NULL,NULL,NULL,NULL,NULL,NULL),(153,54,4,'2026-11-17','08:00:00','09:00:00','Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-09 12:13:56',NULL,NULL,NULL,NULL,NULL,NULL),(154,55,1,'2026-10-29','11:00:00','12:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:14:41',NULL,NULL,NULL,NULL,NULL,NULL),(155,55,1,'2026-10-21','17:00:00','18:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:14:50',NULL,NULL,NULL,NULL,NULL,NULL),(156,55,4,'2026-11-02','11:00:00','12:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:14:59',NULL,NULL,NULL,NULL,NULL,NULL),(157,54,4,'2026-12-22','09:00:00','10:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:15:06',NULL,NULL,NULL,NULL,NULL,NULL),(158,55,4,'2026-11-02','12:00:00','13:30:00','Crown','Pending','',NULL,'2026-10-09 12:15:12',NULL,NULL,NULL,NULL,NULL,NULL),(159,55,4,'2026-11-03','09:00:00','11:00:00','Cosmetic','Pending','',NULL,'2026-10-09 12:15:23',NULL,NULL,NULL,NULL,NULL,NULL),(160,56,4,'2026-10-22','12:00:00','13:00:00','Denture','Pending','',NULL,'2026-10-09 12:16:21',NULL,NULL,NULL,NULL,NULL,NULL),(161,56,4,'2026-10-23','09:00:00','10:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 12:16:31',NULL,NULL,NULL,NULL,NULL,NULL),(162,56,4,'2026-10-24','14:00:00','15:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-09 12:16:58',NULL,NULL,NULL,NULL,NULL,NULL),(163,57,1,'2026-10-16','12:00:00','13:00:00','Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-09 12:17:45',NULL,NULL,NULL,NULL,NULL,NULL),(164,57,4,'2026-10-30','09:00:00','10:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-09 12:18:04',NULL,NULL,NULL,NULL,NULL,NULL),(165,57,1,'2026-11-11','09:00:00','10:30:00','Crown','Pending','',NULL,'2026-10-09 12:18:16',NULL,NULL,NULL,NULL,NULL,NULL),(166,57,1,'2026-11-13','09:00:00','10:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:18:27',NULL,NULL,NULL,NULL,NULL,NULL),(167,57,1,'2026-11-16','10:00:00','11:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 12:18:42',NULL,NULL,NULL,NULL,NULL,NULL),(168,59,4,'2026-11-09','12:00:00','13:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:19:38',NULL,NULL,NULL,NULL,NULL,NULL),(169,59,1,'2026-11-05','08:00:00','09:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:19:51',NULL,NULL,NULL,NULL,NULL,NULL),(170,59,1,'2026-11-06','10:00:00','11:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 12:20:00',NULL,NULL,NULL,NULL,NULL,NULL),(171,59,3,'2026-11-11','14:00:00','15:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:20:21',NULL,NULL,NULL,NULL,NULL,NULL),(172,60,1,'2026-11-17','14:00:00','15:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:21:41',NULL,NULL,NULL,NULL,NULL,NULL),(173,58,4,'2026-10-27','08:00:00','09:00:00','Denture','Pending','',NULL,'2026-10-09 12:21:43',NULL,NULL,NULL,NULL,NULL,NULL),(174,60,1,'2026-11-19','13:00:00','15:00:00','Root Canal','Pending','',NULL,'2026-10-09 12:21:51',NULL,NULL,NULL,NULL,NULL,NULL),(175,60,4,'2026-11-21','09:00:00','10:00:00','Denture','Pending','',NULL,'2026-10-09 12:22:07',NULL,NULL,NULL,NULL,NULL,NULL),(176,60,4,'2026-11-24','15:00:00','16:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:22:16',NULL,NULL,NULL,NULL,NULL,NULL),(177,60,4,'2026-11-26','16:00:00','17:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:22:26',NULL,NULL,NULL,NULL,NULL,NULL),(178,58,4,'2026-10-30','10:00:00','11:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-09 12:22:57',NULL,NULL,NULL,NULL,NULL,NULL),(179,61,1,'2026-11-25','09:00:00','10:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:23:25',NULL,NULL,NULL,NULL,NULL,NULL),(180,61,4,'2026-10-30','14:00:00','15:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 12:23:50',NULL,NULL,NULL,NULL,NULL,NULL),(181,61,3,'2026-11-10','16:00:00','17:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:25:53',NULL,NULL,NULL,NULL,NULL,NULL),(182,61,1,'2026-11-16','12:00:00','13:00:00','Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-09 12:26:02',NULL,NULL,NULL,NULL,NULL,NULL),(183,63,1,'2026-11-11','11:00:00','12:00:00','Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-09 12:27:12',NULL,NULL,NULL,NULL,NULL,NULL),(184,63,3,'2026-11-18','09:00:00','10:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:27:21',NULL,NULL,NULL,NULL,NULL,NULL),(185,62,3,'2026-10-31','09:00:00','11:00:00','Cosmetic','Pending','',NULL,'2026-10-09 12:27:25',NULL,NULL,NULL,NULL,NULL,NULL),(186,63,2,'2026-11-25','15:00:00','17:00:00','Root Canal','Pending','',NULL,'2026-10-09 12:27:31',NULL,NULL,NULL,NULL,NULL,NULL),(187,63,1,'2026-11-30','16:00:00','17:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:27:40',NULL,NULL,NULL,NULL,NULL,NULL),(188,63,1,'2026-11-13','15:00:00','17:00:00','Veneers','Pending','',NULL,'2026-10-09 12:27:48',NULL,NULL,NULL,NULL,NULL,NULL),(189,62,3,'2026-11-10','09:00:00','10:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:28:11',NULL,NULL,NULL,NULL,NULL,NULL),(190,64,4,'2026-11-10','09:00:00','10:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:30:42',NULL,NULL,NULL,NULL,NULL,NULL),(191,64,4,'2026-11-03','12:00:00','13:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-09 12:30:50',NULL,NULL,NULL,NULL,NULL,NULL),(192,64,4,'2026-11-17','14:00:00','15:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-09 12:30:59',NULL,NULL,NULL,NULL,NULL,NULL),(193,64,4,'2026-11-02','14:00:00','15:00:00','Denture','Pending','',NULL,'2026-10-09 12:31:07',NULL,NULL,NULL,NULL,NULL,NULL),(194,64,4,'2026-11-09','09:00:00','10:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:31:15',NULL,NULL,NULL,NULL,NULL,NULL),(195,65,4,'2026-11-03','08:00:00','09:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:31:32',NULL,NULL,NULL,NULL,NULL,NULL),(196,66,4,'2026-10-27','11:00:00','13:00:00','Root Canal','Pending','',NULL,'2026-10-09 12:32:01',NULL,NULL,NULL,NULL,NULL,NULL),(197,66,4,'2026-10-26','09:00:00','10:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:32:09',NULL,NULL,NULL,NULL,NULL,NULL),(198,65,4,'2026-11-02','16:00:00','17:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 12:32:11',NULL,NULL,NULL,NULL,NULL,NULL),(199,66,4,'2026-10-26','08:00:00','09:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-09 12:32:18',NULL,NULL,NULL,NULL,NULL,NULL),(200,66,4,'2026-10-26','10:00:00','11:00:00','Denture','Pending','',NULL,'2026-10-09 12:32:27',NULL,NULL,NULL,NULL,NULL,NULL),(201,66,1,'2026-10-28','08:00:00','09:00:00','Denture','Pending','',NULL,'2026-10-09 12:32:36',NULL,NULL,NULL,NULL,NULL,NULL),(202,65,4,'2026-11-02','10:00:00','11:00:00','Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-09 12:33:17',NULL,NULL,NULL,NULL,NULL,NULL),(203,67,4,'2026-10-29','09:00:00','10:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:35:44',NULL,NULL,NULL,NULL,NULL,NULL),(204,67,1,'2026-10-28','14:00:00','15:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:35:58',NULL,NULL,NULL,NULL,NULL,NULL),(205,67,3,'2026-10-27','09:00:00','10:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:36:11',NULL,NULL,NULL,NULL,NULL,NULL),(206,67,3,'2026-10-26','09:00:00','10:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-09 12:36:20',NULL,NULL,NULL,NULL,NULL,NULL),(207,67,1,'2026-11-19','15:00:00','16:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:36:30',NULL,NULL,NULL,NULL,NULL,NULL),(208,69,4,'2026-11-17','10:00:00','11:30:00','Crown','Pending','',NULL,'2026-10-09 12:39:49',NULL,NULL,NULL,NULL,NULL,NULL),(209,69,4,'2026-12-08','09:00:00','10:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:40:33',NULL,NULL,NULL,NULL,NULL,NULL),(210,70,2,'2026-11-10','10:00:00','11:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:44:46',NULL,NULL,NULL,NULL,NULL,NULL),(211,70,4,'2026-12-10','08:00:00','09:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:45:20',NULL,NULL,NULL,NULL,NULL,NULL),(212,68,5,'2026-10-27','09:00:00','10:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 12:46:02',NULL,NULL,NULL,NULL,NULL,NULL),(213,68,5,'2026-10-28','13:00:00','14:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 12:46:09',NULL,NULL,NULL,NULL,NULL,NULL),(214,68,5,'2026-10-27','13:00:00','14:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 12:46:17',NULL,NULL,NULL,NULL,NULL,NULL),(215,68,5,'2026-10-26','10:00:00','11:00:00','Oral Prophylaxis or Cleaning','Pending','',NULL,'2026-10-09 12:46:24',NULL,NULL,NULL,NULL,NULL,NULL),(216,68,5,'2026-10-29','13:00:00','14:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:46:45',NULL,NULL,NULL,NULL,NULL,NULL),(217,71,5,'2026-10-27','11:00:00','12:00:00','Denture','Pending','',NULL,'2026-10-09 12:48:00',NULL,NULL,NULL,NULL,NULL,NULL),(218,71,5,'2026-10-24','09:00:00','10:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:48:15',NULL,NULL,NULL,NULL,NULL,NULL),(219,71,5,'2026-10-17','09:00:00','10:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:48:25',NULL,NULL,NULL,NULL,NULL,NULL),(220,71,5,'2026-10-31','16:00:00','17:00:00','General Dentistry','Pending','',NULL,'2026-10-09 12:48:32',NULL,NULL,NULL,NULL,NULL,NULL),(221,72,1,'2026-11-20','09:00:00','10:00:00','Denture','Pending','',NULL,'2026-10-09 12:48:40',NULL,NULL,NULL,NULL,NULL,NULL),(222,72,1,'2026-12-15','08:00:00','09:00:00','Denture','Pending','',NULL,'2026-10-09 12:49:16',NULL,NULL,NULL,NULL,NULL,NULL),(223,73,5,'2026-10-28','09:00:00','11:00:00','Root Canal','Pending','',NULL,'2026-10-09 12:50:30',NULL,NULL,NULL,NULL,NULL,NULL),(224,73,5,'2026-10-29','14:00:00','15:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:50:37',NULL,NULL,NULL,NULL,NULL,NULL),(225,73,5,'2026-10-31','09:00:00','10:00:00','Fillings or Pasta','Pending','',NULL,'2026-10-09 12:50:52',NULL,NULL,NULL,NULL,NULL,NULL),(226,74,5,'2026-12-22','08:00:00','09:00:00','Denture','Pending','',NULL,'2026-10-09 12:54:52',NULL,NULL,NULL,NULL,NULL,NULL),(227,74,5,'2026-11-24','09:00:00','10:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 12:56:44',NULL,NULL,NULL,NULL,NULL,NULL),(228,75,5,'2026-11-10','08:00:00','09:30:00','Oral Surgery (Tooth Extraction or Bunot)','Pending','',NULL,'2026-10-09 13:00:58',NULL,NULL,NULL,NULL,NULL,NULL),(229,75,5,'2026-12-08','14:00:00','15:00:00','Orthodontics (Braces)','Pending','',NULL,'2026-10-09 13:01:27',NULL,NULL,NULL,NULL,NULL,NULL),(230,2,3,'2026-10-17','09:00:00','10:00:00','Denture','Pending','',NULL,'2026-10-10 05:18:59',NULL,NULL,NULL,NULL,NULL,NULL),(231,2,3,'2026-10-10','11:00:00','12:30:00','Crown','No-Show','',NULL,'2026-10-10 05:19:23',NULL,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clinic_business_hours`
--

DROP TABLE IF EXISTS `clinic_business_hours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clinic_business_hours` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `day_of_week` int(11) NOT NULL,
  `day_name` varchar(20) NOT NULL,
  `is_open` tinyint(1) NOT NULL DEFAULT 1,
  `open_time` time DEFAULT NULL,
  `close_time` time DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_day` (`day_of_week`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clinic_business_hours`
--

LOCK TABLES `clinic_business_hours` WRITE;
/*!40000 ALTER TABLE `clinic_business_hours` DISABLE KEYS */;
INSERT INTO `clinic_business_hours` VALUES (1,0,'Sunday',0,NULL,NULL,'2026-09-15 16:57:14'),(2,1,'Monday',1,'08:00:00','18:00:00','2026-09-15 16:57:14'),(3,2,'Tuesday',1,'08:00:00','18:00:00','2026-09-15 16:57:14'),(4,3,'Wednesday',1,'08:00:00','18:00:00','2026-09-15 16:57:14'),(5,4,'Thursday',1,'08:00:00','18:00:00','2026-09-15 16:57:14'),(6,5,'Friday',1,'08:00:00','18:00:00','2026-09-15 16:57:14'),(7,6,'Saturday',1,'08:00:00','18:00:00','2026-09-15 16:57:14');
/*!40000 ALTER TABLE `clinic_business_hours` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clinic_services`
--

DROP TABLE IF EXISTS `clinic_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clinic_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 30,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clinic_services`
--

LOCK TABLES `clinic_services` WRITE;
/*!40000 ALTER TABLE `clinic_services` DISABLE KEYS */;
INSERT INTO `clinic_services` VALUES (1,'General Dentistry','General dental care',60,'Active','2026-09-15 18:08:41'),(2,'Orthodontics (Braces)','Braces treatment',60,'Active','2026-09-15 18:08:41'),(3,'Oral Surgery (Tooth Extraction or Bunot)','Tooth extraction',90,'Active','2026-09-15 18:08:41'),(4,'Cosmetic','Cosmetic dentistry',120,'Active','2026-09-15 18:08:41'),(5,'Fillings or Pasta','Dental fillings',60,'Active','2026-09-15 18:08:41'),(6,'Root Canal','Root canal treatment',120,'Active','2026-09-15 18:08:41'),(7,'Oral Prophylaxis or Cleaning','Cleaning',60,'Active','2026-09-15 18:08:41'),(8,'Denture','Denture',60,'Active','2026-09-15 18:08:41'),(9,'Teeth Whitening','Whitening',90,'Active','2026-09-15 18:08:41'),(10,'Crown','Crown',90,'Active','2026-09-15 18:08:41'),(11,'Bridge','custom-made, fixed dental restoration',90,'Active','2026-09-15 18:08:41'),(12,'Veneers','Veneers',120,'Active','2026-09-15 18:08:41'),(13,'Dental Check-up','Check-up',30,'Active','2026-09-15 18:08:41');
/*!40000 ALTER TABLE `clinic_services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clinic_settings`
--

DROP TABLE IF EXISTS `clinic_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clinic_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `clinic_name` varchar(255) NOT NULL DEFAULT 'Abalos Dental Clinic',
  `slot_interval_minutes` int(11) NOT NULL DEFAULT 30,
  `max_patients_per_day` int(11) NOT NULL DEFAULT 10,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clinic_settings`
--

LOCK TABLES `clinic_settings` WRITE;
/*!40000 ALTER TABLE `clinic_settings` DISABLE KEYS */;
INSERT INTO `clinic_settings` VALUES (1,'Abalos Dental Clinic',30,10,'2026-09-15 16:57:14');
/*!40000 ALTER TABLE `clinic_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dentist_day_offs`
--

DROP TABLE IF EXISTS `dentist_day_offs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dentist_day_offs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dentist_id` int(11) NOT NULL,
  `off_date` date NOT NULL,
  `reason` varchar(255) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_dentist_off_date` (`dentist_id`,`off_date`),
  KEY `idx_dentist_id` (`dentist_id`),
  KEY `idx_off_date` (`off_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dentist_day_offs`
--

LOCK TABLES `dentist_day_offs` WRITE;
/*!40000 ALTER TABLE `dentist_day_offs` DISABLE KEYS */;
/*!40000 ALTER TABLE `dentist_day_offs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dentist_schedules`
--

DROP TABLE IF EXISTS `dentist_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dentist_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dentist_id` int(11) NOT NULL,
  `schedule_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `dentist_id` (`dentist_id`),
  CONSTRAINT `dentist_schedules_ibfk_1` FOREIGN KEY (`dentist_id`) REFERENCES `dentists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dentist_schedules`
--

LOCK TABLES `dentist_schedules` WRITE;
/*!40000 ALTER TABLE `dentist_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `dentist_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dentists`
--

DROP TABLE IF EXISTS `dentists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dentists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dentist_name` varchar(150) NOT NULL,
  `specialization` varchar(150) DEFAULT '',
  `contact_number` varchar(50) DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `user_id` int(11) DEFAULT NULL,
  `rest_day` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dentist_user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dentists`
--

LOCK TABLES `dentists` WRITE;
/*!40000 ALTER TABLE `dentists` DISABLE KEYS */;
INSERT INTO `dentists` VALUES (1,'Dr. Juan Dela Cruz','General Dentistry','09171234501','Active',10,6,'2026-09-15 16:53:18'),(2,'Dr. Renz','General Dentistry','09171234502','Active',6,1,'2026-09-15 16:53:18'),(3,'Dr. Lizelle Gonzales','General Dentistry','09171234503','Active',NULL,4,'2026-09-15 19:21:16'),(4,'Dr. Abby','General Dentistry','09171234504','Active',NULL,3,'2026-09-18 08:15:21'),(5,'Dr. Angelion Orlina','General Dentistry','09171234505','Active',79,5,'2026-10-09 12:44:18');
/*!40000 ALTER TABLE `dentists` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `medical_records`
--

DROP TABLE IF EXISTS `medical_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `medical_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `record_date` date NOT NULL,
  `diagnosis` text DEFAULT NULL,
  `treatment` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `medical_records_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `medical_records`
--

LOCK TABLES `medical_records` WRITE;
/*!40000 ALTER TABLE `medical_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `medical_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'System',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `appointment_id` (`appointment_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,1,1,'Appointment Confirmed','Your appointment on 2026-09-15 has been confirmed.','Appointment','2026-09-15 17:02:21'),(2,2,3,'Appointment Cancelled','Your appointment on 2026-09-20 has been cancelled.','Appointment','2026-09-15 17:48:15'),(3,2,3,'Appointment Completed','Your appointment on 2026-09-20 has been completed.','Appointment','2026-09-15 17:48:35'),(4,2,3,'Appointment Confirmed','Your appointment on 2026-09-20 has been confirmed.','Appointment','2026-09-15 17:49:03'),(5,2,3,'Appointment Rescheduled','Your appointment has been moved to 2026-09-21 at 14:00.','Appointment','2026-09-15 18:17:18'),(6,2,3,'No-Show','You were marked as No-Show for your appointment on 2026-09-21 at 14:00. Please contact the clinic.','System','2026-09-16 06:31:42'),(7,2,3,'Appointment Confirmed','Your appointment on 2026-09-21 has been confirmed.','Appointment','2026-09-16 07:14:48'),(8,2,3,'No-Show','You were marked as No-Show for your appointment on 2026-09-21 at 14:00:00. Please contact the clinic.','Appointment','2026-09-16 07:14:51'),(9,2,3,'Appointment Confirmed','Your appointment on 2026-09-21 has been confirmed.','Appointment','2026-09-16 07:14:54'),(10,1,1,'No-Show','You were marked as No-Show for your appointment on 2026-09-15 at 09:00. Please contact the clinic.','System','2026-09-16 07:15:07'),(11,2,3,'Appointment Confirmed','Your appointment on 2026-09-21 has been confirmed.','Appointment','2026-09-16 07:36:14'),(12,1,1,'Appointment Completed','Your appointment on 2026-09-18 has been completed.','Appointment','2026-09-16 08:28:53'),(13,2,2,'Appointment Cancelled','Your appointment on 2026-09-18 at 09:00 AM has been cancelled. The slot is now available for other patients. Reason: family emergency','Appointment','2026-09-18 07:49:45'),(14,1,1,'Appointment Cancelled','Your appointment on 2026-09-18 at 09:00 AM has been cancelled. The slot is now available for other patients. Reason: I have a family emergency','Appointment','2026-09-18 07:58:46'),(15,1,7,'Appointment Cancelled','Your appointment on 2026-09-21 at 01:30 PM has been cancelled. The slot is now available for other patients. Reason: i have family emergency','Appointment','2026-09-18 07:59:58'),(16,1,6,'Appointment Cancelled','Your appointment on 2026-09-19 at 10:00 AM has been cancelled. The slot is now available for other patients.','Appointment','2026-09-18 09:21:05'),(17,1,9,'Appointment Confirmed','Your appointment on 2026-09-21 has been confirmed.','Appointment','2026-09-18 09:39:36'),(18,2,3,'Appointment Reminder Sent','Reminder sent to michaelapacamana23@gmail.com for appointment on 2026-09-21 at 02:00 PM.','Appointment','2026-09-21 08:45:52'),(19,2,3,'Appointment Reminder Sent','Reminder sent to michaelapacamana23@gmail.com for appointment on 2026-09-21 at 02:00 PM.','Appointment','2026-09-21 08:46:28'),(20,1,9,'Appointment Reminder Sent','Reminder sent to roceltagudar1@gmail.com for appointment on 2026-09-21 at 11:00 AM.','Appointment','2026-09-21 08:46:52'),(21,2,3,'Appointment Reminder Sent','Reminder sent to michaelapacamana23@gmail.com for appointment on 2026-09-21 at 02:00 PM.','Appointment','2026-09-21 08:52:49'),(22,1,9,'Appointment Reminder Sent','Reminder sent to roceltagudar1@gmail.com for appointment on 2026-09-21 at 11:00 AM.','Appointment','2026-09-21 08:57:24'),(23,2,5,'No-Show','You were automatically marked as No-Show for your appointment on 2026-09-18 at 10:00. Please contact the clinic to reschedule.','System','2026-09-21 09:07:14'),(24,1,8,'No-Show','You were automatically marked as No-Show for your appointment on 2026-09-19 at 14:00. Please contact the clinic to reschedule.','System','2026-09-21 09:07:14'),(25,1,9,'No-Show','You were automatically marked as No-Show for your appointment on 2026-09-21 at 11:00. Please contact the clinic to reschedule.','System','2026-09-21 09:07:14'),(26,2,3,'Appointment Confirmed','Your appointment on 2026-09-21 has been confirmed.','Appointment','2026-09-21 09:07:52'),(27,1,6,'Appointment Reminder Sent','Reminder sent to roceltagudar1@gmail.com for appointment on 2026-09-19 at 10:00 AM.','Appointment','2026-09-21 09:09:34'),(28,1,11,'Auto Reminder','Day-3 auto reminder sent to roceltagudar1@gmail.com.','Appointment','2026-09-21 10:21:27'),(29,2,10,'Auto Reminder','Day-1 auto reminder sent to michaelapacamana23@gmail.com.','Appointment','2026-09-24 06:30:37'),(30,1,11,'No-Show','You were automatically marked as No-Show for your appointment on 2026-09-24 at 13:00. Please contact the clinic to reschedule.','System','2026-09-24 07:32:45'),(31,2,10,'No-Show','You were automatically marked as No-Show for your appointment on 2026-09-25 at 09:00. Please contact the clinic to reschedule.','System','2026-09-25 04:00:07'),(32,1,11,'Appointment Reminder Sent','Reminder sent to roceltagudar1@gmail.com for appointment on 2026-09-24 at 01:00 PM.','Appointment','2026-09-27 16:50:26'),(33,29,89,'Appointment Completed','Your appointment on 2026-10-05 has been completed.','Appointment','2026-10-03 15:28:37'),(34,1,6,'New Appointment Request','Your appointment request is waiting for confirmation.','Appointment','2026-10-03 15:30:33'),(35,1,6,'No-Show','You were automatically marked as No-Show for your appointment on 2026-09-19 at 10:00. Please contact the clinic to reschedule.','System','2026-10-03 15:30:33'),(36,1,7,'New Appointment Request','Your appointment request is waiting for confirmation.','Appointment','2026-10-03 15:30:42'),(37,1,7,'No-Show','You were automatically marked as No-Show for your appointment on 2026-09-21 at 13:30. Please contact the clinic to reschedule.','System','2026-10-03 15:30:42'),(38,2,4,'New Appointment Request','Your appointment request is waiting for confirmation.','Appointment','2026-10-03 15:30:55'),(39,2,4,'No-Show','You were automatically marked as No-Show for your appointment on 2026-09-11 at 10:00. Please contact the clinic to reschedule.','System','2026-10-03 15:30:55'),(40,10,15,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-05 at 08:00. Please contact the clinic to reschedule.','System','2026-10-05 02:00:53'),(41,17,76,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-05 at 09:12. Please contact the clinic to reschedule.','System','2026-10-05 02:42:53'),(42,14,55,'Appointment Confirmed','Your appointment on 2026-10-06 has been confirmed.','Appointment','2026-10-05 08:56:50'),(43,11,17,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-06 at 12:00. Please contact the clinic to reschedule.','System','2026-10-06 08:51:19'),(44,9,32,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-06 at 14:00. Please contact the clinic to reschedule.','System','2026-10-06 08:51:19'),(45,14,55,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-06 at 10:00. Please contact the clinic to reschedule.','System','2026-10-06 08:51:19'),(46,18,88,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-06 at 13:30. Please contact the clinic to reschedule.','System','2026-10-06 08:51:19'),(47,34,102,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-06 at 12:00. Please contact the clinic to reschedule.','System','2026-10-06 08:51:19'),(48,32,95,'Appointment Confirmed','Your appointment on 2026-10-07 has been confirmed.','Appointment','2026-10-06 10:46:58'),(49,32,95,'Appointment Confirmed','Your appointment on 2026-10-07 has been confirmed.','Appointment','2026-10-06 11:58:54'),(50,32,95,'Appointment Confirmed','Your appointment on 2026-10-07 has been confirmed.','Appointment','2026-10-06 11:58:54'),(51,1,11,'Reschedule / Cancel Options Sent','Sent reschedule and cancel options to roceltagudar1@gmail.com.','Appointment','2026-10-06 12:54:32'),(52,1,11,'Reschedule / Cancel Options Sent','Sent reschedule and cancel options to roceltagudar1@gmail.com.','Appointment','2026-10-06 13:15:11'),(53,32,95,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-07 at 08:00. Please contact the clinic to reschedule.','System','2026-10-07 02:16:14'),(54,34,104,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-07 at 09:00. Please contact the clinic to reschedule.','System','2026-10-07 03:32:36'),(55,10,16,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-07 at 11:00. Please contact the clinic to reschedule.','System','2026-10-07 05:21:01'),(56,11,18,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-07 at 14:00. Please contact the clinic to reschedule.','System','2026-10-07 08:01:45'),(57,14,56,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-08 at 09:00. Please contact the clinic to reschedule.','System','2026-10-08 05:39:58'),(58,32,96,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-08 at 13:00. Please contact the clinic to reschedule.','System','2026-10-08 06:38:46'),(59,34,103,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-08 at 15:00. Please contact the clinic to reschedule.','System','2026-10-08 08:47:32'),(60,11,30,'Appointment Confirmed','Your appointment on 2026-10-09 has been confirmed.','Appointment','2026-10-08 12:44:28'),(61,32,97,'Appointment Confirmed','Your appointment on 2026-10-09 has been confirmed.','Appointment','2026-10-08 12:44:42'),(62,11,30,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-09 at 09:00. Please contact the clinic to reschedule.','System','2026-10-09 04:21:53'),(63,32,97,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-09 at 10:00. Please contact the clinic to reschedule.','System','2026-10-09 04:21:53'),(64,30,90,'Appointment Confirmed','Your appointment on 2026-10-09 has been confirmed.','Appointment','2026-10-09 05:32:02'),(65,30,90,'Patient Checked In','Patient checked in for the appointment on 2026-10-09.','Appointment','2026-10-09 05:32:11'),(66,39,116,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-10 at 09:00. Please contact the clinic to reschedule.','System','2026-10-10 04:48:23'),(67,40,117,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-10 at 10:00. Please contact the clinic to reschedule.','System','2026-10-10 04:48:23'),(68,44,122,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-10 at 08:00. Please contact the clinic to reschedule.','System','2026-10-10 04:48:23'),(69,46,126,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-10 at 09:00. Please contact the clinic to reschedule.','System','2026-10-10 04:48:23'),(70,44,124,'Appointment Confirmed','Your appointment on 2026-10-10 has been confirmed.','Appointment','2026-10-10 05:14:27'),(71,44,124,'Appointment Confirmed','Your appointment on 2026-10-10 has been confirmed.','Appointment','2026-10-10 05:14:29'),(72,47,131,'Appointment Confirmed','Your appointment on 2026-10-10 has been confirmed.','Appointment','2026-10-10 05:14:32'),(73,44,123,'Appointment Confirmed','Your appointment on 2026-10-10 has been confirmed.','Appointment','2026-10-10 05:14:35'),(74,39,118,'Appointment Confirmed','Your appointment on 2026-10-10 has been confirmed.','Appointment','2026-10-10 05:14:39'),(75,47,130,'Appointment Confirmed','Your appointment on 2026-10-10 has been confirmed.','Appointment','2026-10-10 05:14:52'),(76,45,128,'Appointment Confirmed','Your appointment on 2026-10-12 has been confirmed.','Appointment','2026-10-10 05:15:20'),(77,44,124,'Patient Checked In','Patient checked in for the appointment on 2026-10-10.','Appointment','2026-10-10 05:16:25'),(78,46,127,'Appointment Confirmed','Your appointment on 2026-10-12 has been confirmed.','Appointment','2026-10-10 05:16:40'),(79,14,57,'Appointment Confirmed','Your appointment on 2026-10-12 has been confirmed.','Appointment','2026-10-10 05:16:44'),(80,2,231,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-10 at 11:00. Please contact the clinic to reschedule.','System','2026-10-10 05:19:33'),(81,39,118,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-10 at 16:41. Please contact the clinic to reschedule.','System','2026-10-10 12:00:22'),(82,44,123,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-10 at 15:00. Please contact the clinic to reschedule.','System','2026-10-10 12:00:22'),(83,47,130,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-10 at 17:00. Please contact the clinic to reschedule.','System','2026-10-10 12:00:22'),(84,47,131,'No-Show','You were automatically marked as No-Show for your appointment on 2026-10-10 at 15:00. Please contact the clinic to reschedule.','System','2026-10-10 12:00:22'),(85,11,31,'Auto Reminder','Day-2 auto reminder sent to Samledesma0130@gmail.com.','Appointment','2026-10-10 13:12:44'),(86,14,57,'Auto Reminder','Day-2 auto reminder sent to patient_test_20261003@testmail.com.','Appointment','2026-10-10 13:12:48'),(87,14,58,'Auto Reminder','Day-2 auto reminder sent to patient_test_20261003@testmail.com.','Appointment','2026-10-10 13:12:53'),(88,14,59,'Auto Reminder','Day-2 auto reminder sent to patient_test_20261003@testmail.com.','Appointment','2026-10-10 13:12:58'),(89,14,60,'Auto Reminder','Day-3 auto reminder sent to patient_test_20261003@testmail.com.','Appointment','2026-10-10 13:13:03'),(90,14,61,'Auto Reminder','Day-3 auto reminder sent to patient_test_20261003@testmail.com.','Appointment','2026-10-10 13:13:07'),(91,14,62,'Auto Reminder','Day-3 auto reminder sent to patient_test_20261003@testmail.com.','Appointment','2026-10-10 13:13:13'),(92,14,66,'Auto Reminder','Day-2 auto reminder sent to patient_test_20261003@testmail.com.','Appointment','2026-10-10 13:13:18'),(93,14,67,'Auto Reminder','Day-3 auto reminder sent to patient_test_20261003@testmail.com.','Appointment','2026-10-10 13:13:22'),(94,14,68,'Auto Reminder','Day-3 auto reminder sent to patient_test_20261003@testmail.com.','Appointment','2026-10-10 13:13:28'),(95,14,71,'Auto Reminder','Day-2 auto reminder sent to patient_test_20261003@testmail.com.','Appointment','2026-10-10 13:13:32'),(96,14,72,'Auto Reminder','Day-3 auto reminder sent to patient_test_20261003@testmail.com.','Appointment','2026-10-10 13:13:37'),(97,30,91,'Auto Reminder','Day-3 auto reminder sent to tagudarceleste3@gmail.com.','Appointment','2026-10-10 13:13:43'),(98,33,98,'Auto Reminder','Day-2 auto reminder sent to juan.soriano35@example.com.','Appointment','2026-10-10 13:13:47');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `patients`
--

DROP TABLE IF EXISTS `patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `patients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `patient_number` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT '',
  `last_name` varchar(100) NOT NULL,
  `gender` varchar(20) DEFAULT 'Male',
  `birth_date` date DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT '',
  `email` varchar(255) DEFAULT '',
  `address` text DEFAULT NULL,
  `dental_history` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `patient_number` (`patient_number`),
  UNIQUE KEY `unique_patient_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=76 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patients`
--

LOCK TABLES `patients` WRITE;
/*!40000 ALTER TABLE `patients` DISABLE KEYS */;
INSERT INTO `patients` VALUES (1,4,'PAT-20260915185329','Rocel','Tumala','Tagudar','Female','2004-09-23','09851555715','roceltagudar1@gmail.com','157 9TH AVENUE WEST GRACE PARK CALOOCAN CITY, BRGY 59',NULL,'2026-09-15 16:53:29'),(2,8,'PAT-20260915-21615','Michaela','','Pacamana','Female',NULL,'09360462755','michaelapacamana23@gmail.com','',NULL,'2026-09-15 17:32:38'),(4,10,'PAT-20260915-688C4','Juan','','Dela Cruz','Male',NULL,'09851555715','dentist@gmail.com',NULL,NULL,'2026-09-15 19:04:36'),(5,NULL,'WALK-2026091810041736','Renelyn','','Cruz','Male',NULL,'09851555715','renelyn.cruz@gmail.com',NULL,NULL,'2026-09-18 08:04:17'),(6,11,'PAT-20260919-49C1F','lizelle','','garcia','Male',NULL,'09454943357','lizelle@gmail.com',NULL,NULL,'2026-09-19 06:16:13'),(7,NULL,'WALK-2026092409484862','Rocel','','Anne Tagudar','Male',NULL,'09178881007','rocelanne.tagudar@gmail.com',NULL,NULL,'2026-09-24 07:48:48'),(8,NULL,'WALK-2026092718430514','Rocel','','Tagudar','Male',NULL,'09178881008','rocel.tagudar@gmail.com',NULL,NULL,'2026-09-27 16:43:05'),(9,12,'PAT-20261003-49EFB','Jonathan','','Canchela','Male',NULL,'09922281894','jonathan.canchela.3@gmail.com',NULL,NULL,'2026-10-03 07:06:01'),(10,13,'PAT-20261003-0B0C0','Renelyn','','Cruz','Male',NULL,'09275613437','cruzrenelyn11@gmail.com',NULL,NULL,'2026-10-03 07:10:56'),(11,14,'PAT-20261003-511AF','sam','','ledesma','Male',NULL,'09086534444','Samledesma0130@gmail.com',NULL,NULL,'2026-10-03 07:15:55'),(12,15,'PAT-20261003-D9DC2','Irish','','Rupa','Male',NULL,'09417660122','Irishannrupa22@gmail.com',NULL,NULL,'2026-10-03 10:39:48'),(13,16,'PAT-20261003-B110A','Lizelle','','Gonzales','Male',NULL,'09835359494','gonzales_lizelle@gmail.com',NULL,NULL,'2026-10-03 10:57:07'),(14,17,'PAT-20261003-19EEA','Juan','','Dela Cruz','Male',NULL,'09171234567','patient_test_20261003@testmail.com',NULL,NULL,'2026-10-03 10:59:26'),(15,18,'PAT-20261003-0B96A','Jastica','','Casidsid','Male',NULL,'09563285456','jasticachristinacasidsid@gmail.com',NULL,NULL,'2026-10-03 11:06:13'),(16,22,'PAT-20261003-3F37F','Maria','','Santos','Male',NULL,'09191234567','patient_multi_test_1791025933154@example.com',NULL,NULL,'2026-10-03 11:12:11'),(17,21,'PAT-20261003-49FB7','Dave','','Dador','Male',NULL,'09167707295','jd.dador@grc.edu.ph',NULL,NULL,'2026-10-03 11:12:17'),(18,20,'PAT-20261003-0CA56','Christian','','Domingo','Male',NULL,'09460417273','domingochristian102401@gmail.com',NULL,NULL,'2026-10-03 11:12:58'),(19,25,'PAT-20261003-38BF7','Carlos','','Mendoza','Male',NULL,'09181110001','carlos.mendoza_1791026071012@example.com',NULL,NULL,'2026-10-03 11:14:29'),(20,26,'PAT-20261003-BCF93','Bea','','Alonzo','Male',NULL,'09181110002','bea.alonzo_1791026071012@example.com',NULL,NULL,'2026-10-03 11:14:30'),(21,27,'PAT-20261003-AB99E','Daniel','','Padilla','Male',NULL,'09181110003','daniel.padilla_1791026071012@example.com',NULL,NULL,'2026-10-03 11:14:32'),(22,28,'PAT-20261003-7D897','Kathryn','','Bernardo','Male',NULL,'09181110004','kathryn.bernardo_1791026071012@example.com',NULL,NULL,'2026-10-03 11:14:34'),(23,29,'PAT-20261003-1A25A','Liza','','Soberano','Male',NULL,'09181110005','liza.soberano_1791026071012@example.com',NULL,NULL,'2026-10-03 11:14:35'),(24,30,'PAT-20261003-2F135','Enrique','','Gil','Male',NULL,'09181110006','enrique.gil_1791026071012@example.com',NULL,NULL,'2026-10-03 11:14:37'),(25,31,'PAT-20261003-8CB8F','Joshua','','Garcia','Male',NULL,'09181110007','joshua.garcia_1791026071012@example.com',NULL,NULL,'2026-10-03 11:14:40'),(26,32,'PAT-20261003-3EFCF','Julia','','Barretto','Male',NULL,'09181110008','julia.barretto_1791026071012@example.com',NULL,NULL,'2026-10-03 11:14:44'),(27,33,'PAT-20261003-A0653','Alden','','Richards','Male',NULL,'09181110009','alden.richards_1791026071012@example.com',NULL,NULL,'2026-10-03 11:14:46'),(28,34,'PAT-20261003-A9A51','Nadine','','Lustre','Male',NULL,'09181110010','nadine.lustre_1791026071012@example.com',NULL,NULL,'2026-10-03 11:14:49'),(29,35,'PAT-20261003-F55B8','Westlie','','Casuncad','Male',NULL,'09615820084','westliecasuncad06@gmail.com',NULL,NULL,'2026-10-03 11:57:45'),(30,36,'PAT-20261004-D5236','Celeste','','Tumala','Male',NULL,'09649214354','tagudarceleste3@gmail.com',NULL,NULL,'2026-10-04 14:22:27'),(31,37,'PAT-20261004-8AF8A','Gayle','','Rodico','Male',NULL,'09669835207','gaylerodico78@gmail.com',NULL,NULL,'2026-10-04 14:31:54'),(32,38,'PAT-20261004-9AC35','Gab','','Bautista','Male',NULL,'09283321819','gab.bautista13@example.com',NULL,NULL,'2026-10-04 15:06:38'),(33,39,'PAT-20261004-4B2BC','Juan','','Soriano','Male',NULL,'09923794026','juan.soriano35@example.com',NULL,NULL,'2026-10-04 15:09:16'),(34,40,'PAT-20261004-CCC46','Jose','','Dela Cruz','Male',NULL,'09915940781','jose.delacruz22@example.com',NULL,NULL,'2026-10-04 15:12:36'),(35,41,'PAT-20261004-80F6E','Ramon','','Villanueva','Male',NULL,'09271034131','ramon.villanueva83@example.com',NULL,NULL,'2026-10-04 15:15:38'),(36,42,'PAT-20261004-1A83B','Mary','','Antonette Bautista','Male',NULL,'09654906144','bautistamaryantonette25@gmail.com',NULL,NULL,'2026-10-04 15:19:11'),(37,43,'PAT-20261004-19342','Jasmine','','Torres','Male',NULL,'09281928327','jasmine.torres95@example.com',NULL,NULL,'2026-10-04 15:19:26'),(38,44,'PAT-20261004-CEDCB','Carlo','','Reyes','Male',NULL,'09175641395','carlo.reyes39@example.com',NULL,NULL,'2026-10-04 15:22:06'),(39,47,'PAT-20261009-F8BB7','Charlotte','','Lonzaga','Male',NULL,'09687927040','charlottelonzaga1@gmail.com',NULL,NULL,'2026-10-09 08:40:36'),(40,46,'PAT-20261009-BB1E0','jianna','','marie cuison','Male',NULL,'09950041925','cuisonjianna@gmail.com',NULL,NULL,'2026-10-09 08:40:54'),(41,48,'PAT-20261009-A6135','Kenneth','','dasalla','Male',NULL,'09275613437','johnkenneth16@gmail.com',NULL,NULL,'2026-10-09 08:51:59'),(42,49,'PAT-20261009-937DB','Beatrizha','','Joy Habbungan','Male',NULL,'09606379509','trishahabbungan@gmail.com',NULL,NULL,'2026-10-09 08:54:28'),(43,50,'PAT-20261009-2104D','precious','','tagudar','Male',NULL,'09669835207','precioustagudar@gmail.com',NULL,NULL,'2026-10-09 08:59:05'),(44,51,'PAT-20261009-67C5F','Kate','','Pascual','Male',NULL,'09272528809','kate.pascual87@example.com',NULL,NULL,'2026-10-09 11:58:22'),(45,52,'PAT-20261009-84909','Luis','','Valdez','Male',NULL,'09932731773','luis.valdez6@gmail.com',NULL,NULL,'2026-10-09 12:00:31'),(46,53,'PAT-20261009-D84C5','Ivy','','Aquino','Male',NULL,'09173911718','ivy.aquino40@example.com',NULL,NULL,'2026-10-09 12:00:35'),(47,54,'PAT-20261009-5386F','Paolo','','Pascual','Male',NULL,'09923834657','paolo.pascual87@example.com',NULL,NULL,'2026-10-09 12:02:44'),(48,55,'PAT-20261009-5F868','Juan','','Mercado','Male',NULL,'09279301031','juan.mercado80@example.com',NULL,NULL,'2026-10-09 12:04:51'),(49,56,'PAT-20261009-757BA','Trisha','','Salazar','Male',NULL,'09199973763','trisha.salazar37@example.com',NULL,NULL,'2026-10-09 12:07:00'),(50,57,'PAT-20261009-E663B','Hazel','','Aquino','Male',NULL,'09192777041','hazel.aquino@gmail.com',NULL,NULL,'2026-10-09 12:07:12'),(51,58,'PAT-20261009-CB091','Joy','','Cruz','Male',NULL,'09180801326','joy.cruz66@example.com',NULL,NULL,'2026-10-09 12:08:31'),(52,59,'PAT-20261009-A680C','Miguel','','Santos','Male',NULL,'09287468723','miguel.santos59@example.com',NULL,NULL,'2026-10-09 12:10:03'),(53,60,'PAT-20261009-0E586','Cathy','','Castillo','Male',NULL,'09179788208','cathy.castillo17@example.com',NULL,NULL,'2026-10-09 12:12:10'),(54,61,'PAT-20261009-49EEE','Ivy','','Garcia','Male',NULL,'09282464662','ivy.garcia3@gmail.com',NULL,NULL,'2026-10-09 12:12:53'),(55,62,'PAT-20261009-F93C9','Grace','','Dela Cruz','Male',NULL,'09279909169','grace.delacruz25@example.com',NULL,NULL,'2026-10-09 12:13:45'),(56,63,'PAT-20261009-D4DA1','Carlo','','Flores','Male',NULL,'09922475107','carlo.flores43@example.com',NULL,NULL,'2026-10-09 12:16:10'),(57,64,'PAT-20261009-1C2B6','Mark','','Villanueva','Male',NULL,'09275427849','mark.villanueva18@example.com',NULL,NULL,'2026-10-09 12:17:36'),(58,65,'PAT-20261009-812D3','Paolo','','Bautista','Male',NULL,'09923334370','paolo.bautista@gmail.com',NULL,NULL,'2026-10-09 12:18:34'),(59,66,'PAT-20261009-EADE2','Hazel','','Bautista','Male',NULL,'09281182449','hazel.bautista27@example.com',NULL,NULL,'2026-10-09 12:19:26'),(60,67,'PAT-20261009-AF85B','Enzo','','Salazar','Male',NULL,'09171640052','enzo.salazar42@example.com',NULL,NULL,'2026-10-09 12:20:58'),(61,68,'PAT-20261009-86580','Lea','','Santos','Male',NULL,'09182805982','lea.santos24@example.com',NULL,NULL,'2026-10-09 12:23:16'),(62,69,'PAT-20261009-D29FE','Cathy','','Ramos','Male',NULL,'09174247613','ramos.cathy@gmail.com',NULL,NULL,'2026-10-09 12:26:55'),(63,70,'PAT-20261009-9DF37','John','','Flores','Male',NULL,'09185869232','john.flores95@example.com',NULL,NULL,'2026-10-09 12:27:00'),(64,72,'PAT-20261009-79A66','Juan','','Bautista','Male',NULL,'09199401965','juan.bautista43@example.com',NULL,NULL,'2026-10-09 12:30:32'),(65,73,'PAT-20261009-EDB27','Juan','','Castillo','Male',NULL,'09287856890','juan.castillo@gmail.com',NULL,NULL,'2026-10-09 12:30:53'),(66,74,'PAT-20261009-CBCF3','Ramon','','Mercado','Male',NULL,'09280608835','ramon.mercado34@example.com',NULL,NULL,'2026-10-09 12:31:54'),(67,75,'PAT-20261009-A9560','Ana','','Domingo','Male',NULL,'09928727743','rafael.domingo88@example.com',NULL,NULL,'2026-10-09 12:35:24'),(68,76,'PAT-20261009-9EA17','Grace','','Ramos','Male',NULL,'09184345581','grace.ramos66@example.com',NULL,NULL,'2026-10-09 12:37:32'),(69,77,'PAT-20261009-140D9','Lea','','Soriano','Male',NULL,'09183785222','lea.soriano@gmail.com',NULL,NULL,'2026-10-09 12:39:15'),(70,78,'PAT-20261009-5E9B6','Hazel','','Reyes','Male',NULL,'09282482964','hazel.reyes3@gmail.com',NULL,NULL,'2026-10-09 12:43:51'),(71,80,'PAT-20261009-2A375','Jose','','Navarro','Male',NULL,'09918760366','jose.navarro62@example.com',NULL,NULL,'2026-10-09 12:47:42'),(72,81,'PAT-20261009-3119D','Luis','','Aquino','Male',NULL,'09171564644','luis.aquino@gmail.com',NULL,NULL,'2026-10-09 12:48:10'),(73,82,'PAT-20261009-9CC45','Sofia','','Santos','Male',NULL,'09916272980','sofia.santos59@example.com',NULL,NULL,'2026-10-09 12:50:22'),(74,83,'PAT-20261009-1E8CB','Jasmine','','Santos','Male',NULL,'09181136173','santos.jasmine@gmail.com',NULL,NULL,'2026-10-09 12:53:59'),(75,84,'PAT-20261009-EF3AD','Claire','','Tanon','Male',NULL,'09223858454','claire.tanon@gmail.com',NULL,NULL,'2026-10-09 12:58:58');
/*!40000 ALTER TABLE `patients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `queue`
--

DROP TABLE IF EXISTS `queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `queue_number` int(11) NOT NULL,
  `queue_date` date NOT NULL,
  `priority` varchar(20) NOT NULL DEFAULT 'Normal',
  `status` varchar(30) NOT NULL DEFAULT 'Waiting',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `service_type` varchar(150) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `queue_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `queue`
--

LOCK TABLES `queue` WRITE;
/*!40000 ALTER TABLE `queue` DISABLE KEYS */;
INSERT INTO `queue` VALUES (1,1,1,'2026-09-15','Normal','Completed','2026-09-15 16:53:29',''),(2,5,1,'2026-09-18','Normal','Waiting','2026-09-18 08:04:17',''),(3,7,1,'2026-09-24','Normal','Waiting','2026-09-24 07:48:48','Teeth Whitening'),(4,8,1,'2026-09-27','Normal','Serving','2026-09-27 16:43:05','Denture'),(5,9,1,'2026-10-05','Normal','Waiting','2026-10-05 02:31:50',''),(6,12,2,'2026-10-05','Normal','Completed','2026-10-05 06:10:51','');
/*!40000 ALTER TABLE `queue` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role` varchar(20) NOT NULL,
  `module` varchar(50) NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`role`,`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES ('admin','appointment',1),('admin','dental_records',1),('admin','patient_records',1),('admin','system_settings',1),('admin','user_management',1),('dentist','patient_records',1);
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `clinic_name` varchar(255) NOT NULL DEFAULT 'Abalos Dental Clinic',
  `address` text NOT NULL,
  `contact_number` varchar(50) DEFAULT '',
  `email` varchar(255) DEFAULT '',
  `opening_time` time NOT NULL DEFAULT '08:00:00',
  `closing_time` time NOT NULL DEFAULT '17:00:00',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,'Abalos Dental Clinic','Malolos Avenue, Caloocan City','','','08:00:00','18:00:00','2026-08-24 09:06:18','2026-09-15 17:06:22');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'staff',
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (4,'roceltagudar1@gmail.com','$2y$10$HBlFmzBJYica.4sG/Uzpse/vJDcuz.cJ1mLcMhZn3iVWK.pYjWiei','Rocel Tagudar','09851555715','patient','1a21d54192852137cc86fb8a3509c4edf3a55ac902293a68e0f2414a28a5a1a0','2026-10-09 17:52:39','2026-08-25 16:50:34'),(6,'dentist@example.com','$2y$10$wLMPzDWSoa7WR/b5Fw1XRua846uh2jvVExTQUHHhRxwyZ3i98WSWi','Dr. Renz',NULL,'dentist',NULL,NULL,'2026-09-15 16:53:29'),(8,'michaelapacamana23@gmail.com','$2y$10$Thdp8V9TAIDE76encuMD0.x1BkztgGpZj.yHgLfBXUTHsH8G3aTeC','Michaela Pacamana','09631518456','patient','4c520bf5ec30e2cb0878a604fe65133e92603f765fe4f95cebb9454cfd324380','2026-09-24 15:35:58','2026-09-15 17:15:04'),(9,'admin@dental.com','$2y$10$GWjL3lxjol1mdmak/sf4GOcnLtjXJ0iFgbIBaPhE3gQaNiU0eaS5K','System Administrator','09360462755','admin',NULL,NULL,'2026-09-15 17:40:48'),(10,'dentist@gmail.com','$2y$10$lAm1HU24onwGOnMMke2wqebIyD24mY21CeRGGN0P/NXGxve4l5wYu','Dr. Juan Dela Cruz','09851555715','dentist',NULL,NULL,'2026-09-15 19:04:21'),(11,'lizelle@gmail.com','$2y$10$/xDF3MsuiTOyZO3fCuRJXepunvWMwHheZqax4YEmo1f9vyECIfBkq','lizelle garcia','09454943357','patient',NULL,NULL,'2026-09-19 06:16:00'),(12,'jonathan.canchela.3@gmail.com','$2y$10$7zTK29aelmNi2UD5vVBwDe6501kjTPn5c0zC8arVA0sioO6kIhecu','Jonathan Canchela','09922281894','patient',NULL,NULL,'2026-10-03 07:05:31'),(13,'cruzrenelyn11@gmail.com','$2y$10$RBBrQpVlqcwgbDXM9cwEBOMNhc1YRfOFlr4IXoxE.6DzGp7ASZioG','Renelyn Cruz','09275613437','patient',NULL,NULL,'2026-10-03 07:10:25'),(14,'Samledesma0130@gmail.com','$2y$10$PM.BWQoxB3s7M7e1n.NVH.lR.fq/El.yyl3qGxgOQJUQR8QyDrgAO','sam ledesma','09086534444','patient',NULL,NULL,'2026-10-03 07:15:30'),(15,'Irishannrupa22@gmail.com','$2y$10$3xKBY.GMeWViPZmNHDQ3yuoIUx6o6iWVujgUqYhwO.75eoIeZmXxS','Irish Rupa','09417660122','patient',NULL,NULL,'2026-10-03 10:39:23'),(16,'gonzales_lizelle@gmail.com','$2y$10$liKKoWql4njfW1aNwsPMPO3LabvL.KeoYnSHTpR/YJ7Nf6OKOYEY.','Lizelle Gonzales','09835359494','patient',NULL,NULL,'2026-10-03 10:56:38'),(17,'patient_test_20261003@testmail.com','$2y$10$HkZ8ujz0pzLzIMkyXTIkBO8sZF2.hCeQghxHkrmQLF4Pcln54X/Pu','Juan Dela Cruz','09171234567','patient','759d38160723507721dd4842269376fed05c5e656696a2afc83a75335e660519','2026-10-03 20:03:20','2026-10-03 10:58:21'),(18,'jasticachristinacasidsid@gmail.com','$2y$10$1HtxmgeAexqtuG01YHL2J.B9wpkcgxtLRVuM.p48GRul2/4bA2ae.','Jastica Casidsid','09563285456','patient',NULL,NULL,'2026-10-03 11:05:37'),(19,'jassanjoy@gmail.com','$2y$10$.1rAUdoI99aWsYjt1pWvh.rTfoV8juaZUtrG7KT0bt0pOn49pOkXq','Jassan Agustin','09920649229','patient',NULL,NULL,'2026-10-03 11:08:52'),(20,'domingochristian102401@gmail.com','$2y$10$aMTXE8iv9bddyu4oCBZIn.s1JfjfYvDOulJ92zrOoU8GXhrLsd1Yi','Christian Domingo','09460417273','patient',NULL,NULL,'2026-10-03 11:11:47'),(21,'jd.dador@grc.edu.ph','$2y$10$LaesblxsjVnH5qhF5YcS0e6SGrF2pFeQdPIwbNWuS20GT0qvfqM7C','Dave Dador','09167707295','patient',NULL,NULL,'2026-10-03 11:11:48'),(22,'patient_multi_test_1791025933154@example.com','$2y$10$brj5fhiBULQ6E3mWhyrg/.cWOYV0sdUeAvE3jpQdjJX6fyHPN6EYW','Maria Santos','09191234567','patient',NULL,NULL,'2026-10-03 11:12:10'),(23,'patient_multi_test_1791025980699@example.com','$2y$10$JlfzdKyNF6aTksReOEmaj.DH4PfEl4sQf6cpGeT5xUDB16vVJ.58y','Maria Santos','09191234567','patient',NULL,NULL,'2026-10-03 11:12:58'),(24,'patient_multi_test_1791026019491@example.com','$2y$10$6dGMQXFbS.pWUpqZ.MAQ2.GWxZ4MNVu3pwJj05WYg9W3tLRJ0MVZe','Maria Santos','09191234567','patient',NULL,NULL,'2026-10-03 11:13:37'),(25,'carlos.mendoza_1791026071012@example.com','$2y$10$ELjIptMet6dMp7h/NzBlE.5u.ENBWJd2myhejQS.3ZpxSKxjMK0ye','Carlos Mendoza','09181110001','patient',NULL,NULL,'2026-10-03 11:14:28'),(26,'bea.alonzo_1791026071012@example.com','$2y$10$X4ExEo4IYZsiGght6hdzDe2EhPPyFmH9RbWNgntDsD.mSw3Lljn1W','Bea Alonzo','09181110002','patient',NULL,NULL,'2026-10-03 11:14:30'),(27,'daniel.padilla_1791026071012@example.com','$2y$10$yHh.1ZF5eHh9ZFVChA7dU.RS0fOZbkr3lYhpzwu/mAS2MGMjrtRRW','Daniel Padilla','09181110003','patient',NULL,NULL,'2026-10-03 11:14:32'),(28,'kathryn.bernardo_1791026071012@example.com','$2y$10$SUA6cg9ebe23VWcx/sQZ7.rLbmVd/Xe5TY.mhiQm6LTSSJVz6WSXC','Kathryn Bernardo','09181110004','patient',NULL,NULL,'2026-10-03 11:14:33'),(29,'liza.soberano_1791026071012@example.com','$2y$10$sfpOYOblGsNAZjzHl1v5ROOEJzn7n.VyJioTkSmeZHqX1Ki5zaYRq','Liza Soberano','09181110005','patient',NULL,NULL,'2026-10-03 11:14:35'),(30,'enrique.gil_1791026071012@example.com','$2y$10$GEH.JWPzVKDbYG9.tMP72uVqmYcgGaQuVBbyp9EjvMRcgyHX2t/..','Enrique Gil','09181110006','patient',NULL,NULL,'2026-10-03 11:14:37'),(31,'joshua.garcia_1791026071012@example.com','$2y$10$qIvrnhilnrg3DNdV74tg.eH6SSWEeJWXSgaou0SyZ.sxUNanSiEUO','Joshua Garcia','09181110007','patient',NULL,NULL,'2026-10-03 11:14:38'),(32,'julia.barretto_1791026071012@example.com','$2y$10$.Owlljr2z0XtQU9JeDRuAOOGmOMyiECGXCaBF3hiYh2M4MdqGBpTC','Julia Barretto','09181110008','patient',NULL,NULL,'2026-10-03 11:14:43'),(33,'alden.richards_1791026071012@example.com','$2y$10$5waKPVX75ytphB2BFKmqm.tWF/SHbtlEWLcswGbCz9FyTx8jkiMD6','Alden Richards','09181110009','patient',NULL,NULL,'2026-10-03 11:14:46'),(34,'nadine.lustre_1791026071012@example.com','$2y$10$d8Luc/OjY/YSF87/W/lTmOAMtHmWLfxMPnhDveCjBm/.6g67XSTja','Nadine Lustre','09181110010','patient',NULL,NULL,'2026-10-03 11:14:48'),(35,'westliecasuncad06@gmail.com','$2y$10$GHWVnBQ/jouruB/ecOmBJe7HQ3zxeKoVX1Nu9I54sVaZA2zYG6Pj.','Westlie Casuncad','09615820084','patient',NULL,NULL,'2026-10-03 11:57:28'),(36,'tagudarceleste3@gmail.com','$2y$10$gHK/YxXSYVibvTLXi1ff8u2bj31iG/4Wd3Rtj3mbPm75nuJToQNzy','Celeste Tumala','09649214354','patient',NULL,NULL,'2026-10-04 14:22:03'),(37,'gaylerodico78@gmail.com','$2y$10$WU9vKYwzdYh.cCl6TaC5ueMztUHmjTglmw1PvRUP6j/PATMn/OhZG','Gayle Rodico','09669835207','patient',NULL,NULL,'2026-10-04 14:30:08'),(38,'gab.bautista13@example.com','$2y$10$IW01d/IGItKqiHS4QOFVj.e98MzsbDKvr7gc.ReR0gDVMe7v4v2Le','Gab Bautista','09283321819','patient',NULL,NULL,'2026-10-04 15:06:20'),(39,'juan.soriano35@example.com','$2y$10$YVxPX867fOsxzp6N1AKkA.MpG8TykfL12gdIXlyKPdjzMFKg8Ihba','Juan Soriano','09923794026','patient',NULL,NULL,'2026-10-04 15:08:53'),(40,'jose.delacruz22@example.com','$2y$10$W.ZIb2h0XyCD3RWSqd4JjOuom1y4xXlzdDMW/e8VuD4pWdgKmrca2','Jose Dela Cruz','09915940781','patient',NULL,NULL,'2026-10-04 15:12:13'),(41,'ramon.villanueva83@example.com','$2y$10$z3R5vMbSIZGB6dIbVQS3d.6zel4SxAqGD8DDGa27h5IbBcuUUJlWW','Ramon Villanueva','09271034131','patient',NULL,NULL,'2026-10-04 15:15:21'),(42,'bautistamaryantonette25@gmail.com','$2y$10$DmMaheWJbZq0lIp0QTtJGuFB4fY9S6jpWCYE3xiLn8D4ESMh5VnLi','Mary Antonette Bautista','09654906144','patient',NULL,NULL,'2026-10-04 15:18:10'),(43,'jasmine.torres95@example.com','$2y$10$p2c0MuVm/u/osnUZInHGUOa1nYnRAeV6JHxK7P9WNB8lRriijIV6y','Jasmine Torres','09281928327','patient',NULL,NULL,'2026-10-04 15:19:16'),(44,'carlo.reyes39@example.com','$2y$10$1WYx/57XeOtfMKOBHqmfquyTjfG6gvr6NfeYrUVMMXiCcu3skMiAS','Carlo Reyes','09175641395','patient',NULL,NULL,'2026-10-04 15:21:34'),(45,'samledesma@gmail.com','$2y$10$qNlEOfGSN5.gcr4Zf.r5L.Q2bYfEz4J9WNh93HvnJfGyksmvFqTzq','Sam Ledesma','09086534444','patient',NULL,NULL,'2026-10-09 08:38:58'),(46,'cuisonjianna@gmail.com','$2y$10$cW4GI9wBbXIYJWI/mr4C0.24XCCdDFqajDWO2KXdnkDtYGvOZ.3zi','jianna marie cuison','09950041925','patient',NULL,NULL,'2026-10-09 08:39:44'),(47,'charlottelonzaga1@gmail.com','$2y$10$NypZ9MNav2OQNw3G4K4vxu6raw4bjbcVPWuCXosvwYERxlgCNsr6a','Charlotte Lonzaga','09687927040','patient',NULL,NULL,'2026-10-09 08:40:19'),(48,'johnkenneth16@gmail.com','$2y$10$UQFMQSe4hzfsVfF7McOZAeSdkNSOrn/nO8XQIsnJjYwDVLzVUqJuC','Kenneth dasalla','09275613437','patient',NULL,NULL,'2026-10-09 08:51:16'),(49,'trishahabbungan@gmail.com','$2y$10$SJeg2sqX3lZcCrn9uqcMXu4CQATYIp0ocH7UmHOS7NjChv07Fstna','Beatrizha Joy Habbungan','09606379509','patient',NULL,NULL,'2026-10-09 08:53:56'),(50,'precioustagudar@gmail.com','$2y$10$l1yxXWbr6iWRdStrI65ftO15A8sEsF4O9N5u6SS30IGCYA3nUwmfq','precious tagudar','09669835207','patient',NULL,NULL,'2026-10-09 08:58:43'),(51,'kate.pascual87@example.com','$2y$10$P53WQwczbfsfDjgitYf8/euOzJ7qykfroOrrQGXuHmCQagN6m9Gqa','Kate Pascual','09272528809','patient',NULL,NULL,'2026-10-09 11:58:10'),(52,'luis.valdez6@gmail.com','$2y$10$KZeRxM4drAOgEORSzfQpMObGakzhu6r9aMOH7vcYJrIiC/ALDDfIO','Luis Valdez','09932731773','patient',NULL,NULL,'2026-10-09 11:59:32'),(53,'ivy.aquino40@example.com','$2y$10$jiWatG9MHLAzwGiEGC7cbuvvj0rB0SAi.ZJ7B.svid2T1ie.LsWW.','Ivy Aquino','09173911718','patient',NULL,NULL,'2026-10-09 12:00:26'),(54,'paolo.pascual87@example.com','$2y$10$ZHC78Hyf5zFvuP7FSCDNNOCR6C4ybkUJJbHpKytPP6DrEvXzFuOQK','Paolo Pascual','09923834657','patient',NULL,NULL,'2026-10-09 12:02:33'),(55,'juan.mercado80@example.com','$2y$10$NX8FjddymmIlvqaoAoSiAusqITBTU9kbAfERAD2jetevJtnh9JwpO','Juan Mercado','09279301031','patient',NULL,NULL,'2026-10-09 12:04:36'),(56,'trisha.salazar37@example.com','$2y$10$cSFfdr.cLVZjV5KJNXuMGeg/BTJxyk0OdYefCtUBN.eaGSukzL4V6','Trisha Salazar','09199973763','patient',NULL,NULL,'2026-10-09 12:06:37'),(57,'hazel.aquino@gmail.com','$2y$10$xEynU2lu2r0FFIa7wg1gIOOsCJJjb2FxW6n06QsbvZrSU1FPlFupW','Hazel Aquino','09192777041','patient',NULL,NULL,'2026-10-09 12:06:41'),(58,'joy.cruz66@example.com','$2y$10$kakjTXtM/dEFn.QuhvSVTumHiy1SSAlcbn8uB4/n/4sqWlg1fRcg.','Joy Cruz','09180801326','patient',NULL,NULL,'2026-10-09 12:08:20'),(59,'miguel.santos59@example.com','$2y$10$7geuUiwsgekW9fWsZxQij.fK4jw/oDkiKFMJ/O/q3whBAVaEzvX5m','Miguel Santos','09287468723','patient',NULL,NULL,'2026-10-09 12:09:53'),(60,'cathy.castillo17@example.com','$2y$10$HE6jI/embwizizTVZ3uKDOZcRWSiOb84mw8UpQXgP1V75wIN6Sw.K','Cathy Castillo','09179788208','patient',NULL,NULL,'2026-10-09 12:11:36'),(61,'ivy.garcia3@gmail.com','$2y$10$.Nh30ah4QzV0WlqZ1ixPaeg1mBE4r4nF7wrq0BumGm.zigvjIgJoa','Ivy Garcia','09282464662','patient',NULL,NULL,'2026-10-09 12:12:22'),(62,'grace.delacruz25@example.com','$2y$10$AXGRobBgWky1Oy.ASk56UuULdsqDx68XXZT8zr/Vim2iUm9IiRgj.','Grace Dela Cruz','09279909169','patient',NULL,NULL,'2026-10-09 12:13:36'),(63,'carlo.flores43@example.com','$2y$10$0TiFEu5fdZtH1SRw9A2Gc.XzRCGj4zRbj29bTeiAdBscMDfLxix66','Carlo Flores','09922475107','patient',NULL,NULL,'2026-10-09 12:16:01'),(64,'mark.villanueva18@example.com','$2y$10$5vFXqmCYVlNdjULD7woM7ekR25K59TJPlfWTO/QVfsVAQcKhrDdZm','Mark Villanueva','09275427849','patient',NULL,NULL,'2026-10-09 12:17:26'),(65,'paolo.bautista@gmail.com','$2y$10$dBu5uyIKx3/8Uv07G1o5wuaK.9HY3W4lsPZ7JNmC6vcbZgTSBKAWG','Paolo Bautista','09923334370','patient',NULL,NULL,'2026-10-09 12:17:32'),(66,'hazel.bautista27@example.com','$2y$10$5dQSpPA.LSpbsiDirrv.l.fgUJ8citsrEsTZTWbcGqjymWzuIIE6q','Hazel Bautista','09281182449','patient',NULL,NULL,'2026-10-09 12:19:14'),(67,'enzo.salazar42@example.com','$2y$10$5UdwW5F5WxVUiXgdFi2dAeq34UYy8hpPDlrlrGpX2iTn6vHSyF7qS','Enzo Salazar','09171640052','patient',NULL,NULL,'2026-10-09 12:20:46'),(68,'lea.santos24@example.com','$2y$10$OTRdmWywGCOlNekhKVry/ezio1glQ9390ET5ogc068s/PYH2dqa/e','Lea Santos','09182805982','patient',NULL,NULL,'2026-10-09 12:22:52'),(69,'ramos.cathy@gmail.com','$2y$10$Nf5L8KDEFYKNXMCICP2oCeczk3TrgY/dYp4L9ol/I6QGU.rDyFzd.','Cathy Ramos','09174247613','patient',NULL,NULL,'2026-10-09 12:26:32'),(70,'john.flores95@example.com','$2y$10$jqsImQWH.XObowkrcdU5hu4Cp9REkdJiCBBSjeavYvHfvIPrGPCJy','John Flores','09185869232','patient',NULL,NULL,'2026-10-09 12:26:48'),(71,'hazel.castillo62@example.com','$2y$10$SBrh8RXpFNUFmKPLo6wbIuoJwySa1azUEmIlv77xNfe/Hxf/Cbnsa','Castillo Hazel','09274216073','patient',NULL,NULL,'2026-10-09 12:28:27'),(72,'juan.bautista43@example.com','$2y$10$quXOPIdd8IWq5WmuT/I3W.33O9awUOsfZ/gXwBurky4Mh.EFGfqBm','Juan Bautista','09199401965','patient',NULL,NULL,'2026-10-09 12:30:22'),(73,'juan.castillo@gmail.com','$2y$10$hlqqd.gDdomoLvbo/rDwye7h.o/Uw7Y5F3pgbGDLxqEYX9tLj5g.G','Juan Castillo','09287856890','patient',NULL,NULL,'2026-10-09 12:30:31'),(74,'ramon.mercado34@example.com','$2y$10$KJ5J8ms.UCS4jhRK7cXQWedGSVfm4yV0/Pz0vn8dicchdCWvd6L32','Ramon Mercado','09280608835','patient',NULL,NULL,'2026-10-09 12:31:42'),(75,'rafael.domingo88@example.com','$2y$10$MlLAqZgVKrBjoXFzSEOaNe0lh3YHMKZ5roSFrQ4h8rJerIFSwW3iy','Ana Domingo','09928727743','patient',NULL,NULL,'2026-10-09 12:35:13'),(76,'grace.ramos66@example.com','$2y$10$RPJCZG/tw75TiVevwsrHaOsyyCptr4hf.pcEKyiEbTJEEe60FMJ8u','Grace Ramos','09184345581','patient',NULL,NULL,'2026-10-09 12:37:14'),(77,'lea.soriano@gmail.com','$2y$10$CiiHrZe1UslwZspuV.YqPeWoVHpSeisLK2Bd/2vML25eIyXfulDWO','Lea Soriano','09183785222','patient',NULL,NULL,'2026-10-09 12:38:46'),(78,'hazel.reyes3@gmail.com','$2y$10$tYGF7CZiO/Sx3kD.Trud6Oz.VRMAVo8UZivDYn9/06F9e8.JsAIua','Hazel Reyes','09282482964','patient',NULL,NULL,'2026-10-09 12:43:10'),(79,'orlina@dental.com','$2y$10$w/nNT55ewu35bnuoRe9G8.j4VryQwxfdV/6m3UE68aW/q3IszewLm','Dr. Angelion Orlina',NULL,'dentist',NULL,NULL,'2026-10-09 12:44:18'),(80,'jose.navarro62@example.com','$2y$10$72rcVfEYyLJiWY5HqMsb3e8l1nUpuLMU8tjjR/YzE6V8TxbM9Ynvm','Jose Navarro','09918760366','patient',NULL,NULL,'2026-10-09 12:47:21'),(81,'luis.aquino@gmail.com','$2y$10$u8v9hfb5mEa/.szkpcVPIutmMIZYh0vSANFpqdn0VafNAAcBU72Je','Luis Aquino','09171564644','patient',NULL,NULL,'2026-10-09 12:47:44'),(82,'sofia.santos59@example.com','$2y$10$.vNnovrYgX3PKqO9EpIY0OwwwbhoJb7x8wdXK28tTI4hjIjuAyzkK','Sofia Santos','09916272980','patient',NULL,NULL,'2026-10-09 12:50:14'),(83,'santos.jasmine@gmail.com','$2y$10$c6zGldM5W5HeXt246jg94uWfUjc30GBgM71FJI8/pzKXJd.P/pxFm','Jasmine Santos','09181136173','patient',NULL,NULL,'2026-10-09 12:53:18'),(84,'claire.tanon@gmail.com','$2y$10$djyNShVbcO/njYdT1ohiMek9.Rw4CITvGqn9NoM/nHoRgLZTvJ1EG','Claire Tanon','09223858454','patient',NULL,NULL,'2026-10-09 12:58:05');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-10 21:13:48
