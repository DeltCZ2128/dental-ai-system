# An AI-Based Intelligent Dental Appointment Scheduling System Using Machine Learning for Optimized Clinic Management

An intelligent web-based clinic management and appointment scheduling system developed for **Abalos Dental Clinic**. The system features real-time patient appointment booking, automated SMS/email reminders, front-desk live queue management, and a 7-day predictive demand forecasting module using machine learning algorithms to balance clinical workloads and eliminate scheduling bottlenecks.

---

## Group Members & Roles
* **Tagudar, Rocel Anne T.** – Project Manager / Documentation / Database Developer / Back-End Developer
* **Pacamana, Michaela R.** – Documentation / Quality Assurance / Front-End Developer
* **Ledesma, Samuelle G.** – Documentation / Evaluation Form Designer
* **Cruz, Renelyn P.** – Documentation Assistant / PPT Designer
* **Canchela, Jonathan R.** – Documentation

* **Adviser:** Mrs. Anne Camille M. Maupay, MIT  
* **Institution:** Global Reciprocal Colleges — College of Computer Studies (BSIT)

---

## Tech Stack
* **Frontend:** HTML5, CSS3, JavaScript (Vanilla ES6+), Bootstrap 5, Bootstrap Icons, Chart.js
* **Backend:** Native PHP (v8.1+), Object-Oriented Architecture, PDO Database Layer, PHPMailer
* **Database:** MySQL / MariaDB (InnoDB Storage Engine)
* **Server Environment:** Apache Web Server (XAMPP Server Suite)
* **Tools & Version Control:** Git, GitHub, VS Code

---

## System Requirements & Prerequisites
Before you begin, ensure you have the following installed on your local machine:
* **XAMPP Server Suite** (v8.1 or higher with Apache and MySQL/MariaDB)
* **PHP** (v8.1 or higher with `pdo_mysql`, `mbstring`, `openssl`, `curl` extensions enabled)
* **Git** (for cloning the repository)
* **Modern Web Browser** (Google Chrome, Microsoft Edge, or Mozilla Firefox)

---

## Installation & Setup Instructions

Follow these step-by-step commands to set up the project locally:

### 1. Clone the Repository
Clone the repository directly inside your local XAMPP `htdocs` directory:
```bash
cd C:\xampp\htdocs
git clone https://github.com/DeltCZ2128/dental-ai-system.git dental-ai-system-fixed
cd dental-ai-system-fixed
```

*(Alternatively, download the repository as a ZIP archive and extract it directly into `C:\xampp\htdocs\dental-ai-system-fixed`.)*

### 2. Verify Directory Permissions
Ensure that the `uploads/` directory inside `dental-ai-system-fixed` is writable so profile photos and uploaded dental attachments can be stored properly.

---

## Database Setup & Configuration

Follow these steps to configure your local database:

1. **Start Local Database Server:**
   * Open the **XAMPP Control Panel**.
   * Click **Start** for both **Apache** and **MySQL**.

2. **Create New Database:**
   * Open your web browser and navigate to phpMyAdmin:
     ```
     http://localhost/phpmyadmin
     ```
   * Click **New** on the left navigation panel.
   * Enter the Database name: `dental_ai_system`
   * Select collation: `utf8mb4_general_ci`
   * Click **Create**.

3. **Import Database Schema:**
   * Select the newly created database `dental_ai_system` in the left sidebar.
   * Click the **Import** tab on the top navigation bar.
   * Click **Choose File** and select `dental_ai.sql` located in the root directory of this project.
   * Scroll down and click **Import** (or **Go**).

4. **Verify Database Configuration:**
   * Open `config.php` in the project root directory. Verify that your database connection settings match your local environment:
     ```php
     define('DB_HOST', 'localhost');
     define('DB_NAME', 'dental_ai_system');
     define('DB_USER', 'root');
     define('DB_PASS', '');
     ```

---

## Running the Application

1. Ensure **Apache** and **MySQL** are running in your XAMPP Control Panel.
2. Open your web browser and navigate to:
   ```
   http://localhost/dental-ai-system-fixed/login.php
   ```

---

## Default Login Credentials

### 1. Clinic Administrator Portal
* **URL:** `http://localhost/dental-ai-system-fixed/login.php`
* **Email / Username:** `admin@dental.com`
* **Password:** `admin123`
* **Permissions:** Full clinic oversight, appointments confirmation, dentist roster & rest day management, financial/clinical reports with printable letterhead, settings & business rules, database backup & disaster recovery.

### 2. Attending Dentist Portal
* **URL:** `http://localhost/dental-ai-system-fixed/login.php`
* **Email / Username:** `delacruz@gmail.com`
* **Password:** `dentist123`
* **Permissions:** Doctor schedule management, consultation queue, patient dental charting, medical history review, rest day & clinical leave filing.

### 3. Patient Portal
* **URL:** `http://localhost/dental-ai-system-fixed/login.php`
* **Email / Username:** `patient@test.com`
* **Password:** `patient123`
* **Permissions:** Interactive calendar booking, appointment slot selection, real-time status tracking, concern submission, and profile settings.
