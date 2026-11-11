# ABALOS DENTAL CLINIC
## Web-Based Dental Clinic Management and Appointment System
### Comprehensive User Manual & Operational Guide

---

**System Version:** 2.0 (CapStone Edition)  
**Document Type:** System User Manual & Technical Operating Guide  
**Prepared For:** Capstone Defense Panel, Clinic Administrators, Dentists, and Patients  
**Clinic Location:** Malolos Avenue, Bagong Barrio, Caloocan City  
**Operating Hours:** Monday to Saturday: 8:00 AM – 6:00 PM (Sunday: Closed)

---

## Talaan ng Nilalaman (Table of Contents)

1. [Kabanata 1: Pangkalahatang Kaalaman sa System (System Overview)](#kabanata-1-pangkalahatang-kaalaman-sa-system)
   - 1.1 Layunin ng System
   - 1.2 Mga Antas ng Gumagamit (User Roles & Access Levels)
   - 1.3 System Requirements
2. [Kabanata 2: Pagpaparehistro at Pag-login (Authentication)](#kabanata-2-pagpaparehistro-at-pag-login)
   - 2.1 Pag-login sa System
   - 2.2 Pagpaparehistro ng Bagong Pasyente
   - 2.3 One-Time Password (OTP) Email Verification
   - 2.4 Walk-in Patient QR Registration
3. [Kabanata 3: Patient Portal Module](#kabanata-3-patient-portal-module)
   - 3.1 Patient Dashboard
   - 3.2 Hakbang sa Pag-book ng Appointment
   - 3.3 Patient Safety Rules (2 Appointments Per Day Limit)
   - 3.4 Pamamahala ng Sariling Schedule (My Appointments)
4. [Kabanata 4: Dentist Portal Module](#kabanata-4-dentist-portal-module)
   - 4.1 Dentist Dashboard
   - 4.2 Arawang Listahan ng Pasyente (Dentist Appointments)
   - 4.3 Live Queue at Dental Consultation Charting
5. [Kabanata 5: Admin Portal Module (Pangunahing Gamit sa Defense)](#kabanata-5-admin-portal-module)
   - 5.1 Admin Dashboard at Clinic Analytics
   - 5.2 Appointments Management (Status Actions & Rescheduling)
   - 5.3 Dentist Absence & Appointment Reassignment Protocol
   - 5.4 Dentist Schedule Management (Rest Days & Emergency Leave)
   - 5.5 Patient Records Search at Pag-print ng Medical Record
   - 5.6 Clinic Reports na may Opisyal na Letterhead
   - 5.7 Clinic Queue Display System
6. [Kabanata 6: Mga Tanong sa Defense at Troubleshooting (Panel FAQ)](#kabanata-6-mga-tanong-sa-defense-at-troubleshooting)

---

<div style="page-break-after: always;"></div>

## Kabanata 1: Pangkalahatang Kaalaman sa System

### 1.1 Layunin ng System
Ang **Abalos Dental Clinic Management and Appointment System** ay isang modernong web-based application na binuo upang i-automate ang operasyon ng dental clinic. Pinapalitan nito ang tradisyunal na manual logbook tungo sa isang sentralisadong digital platform para sa:
- Online scheduling at appointment booking ng mga pasyente.
- Awtomatikong pag-detect ng conflict sa schedule ng dentista.
- Pamamahala sa mga biglaang pagliban (absence) ng dentista at mabilisang paglilipat (reassignment) ng apektadong pasyente.
- Pag-iingat ng kumpletong dental at medical record ng bawat pasyente.
- Paggawa ng opisyal na clinic reports na may legal na letterhead at lagda.

### 1.2 Mga Antas ng Gumagamit (User Roles & Access Levels)

| Role | Pangunahing Kakayahan |
| :--- | :--- |
| **System Administrator (Admin)** | May ganap na kontrol sa clinic: Pamamahala ng lahat ng appointments, pag-reassign ng appointments kapag absent ang doktor, pag-apruba ng dentist rest days at leave, pag-print ng patient medical records, pag-generate ng financial at operational reports, at system backups. |
| **Dentist (Attending Doctor)** | Pagtingin sa sariling schedule, pagtawag ng pasyente sa queue (Serving / Complete), pagtatala ng clinical diagnosis at consultation notes, at pag-update ng profile. |
| **Patient (Registered User)** | Pagtingin sa mga bakanteng oras ng dentista, pag-book ng appointment gamit ang interactive calendar, pag-check sa status ng booking, at pag-access sa sariling appointment history. |

### 1.3 System Requirements
- **Web Server:** Apache (via XAMPP)
- **Database:** MariaDB / MySQL
- **Programming Language:** PHP 8.x
- **Frontend Framework:** Bootstrap 5, HTML5, CSS3, Vanilla JavaScript
- **Supported Browsers:** Google Chrome, Microsoft Edge, Mozilla Firefox, Safari

---

<div style="page-break-after: always;"></div>

## Kabanata 2: Pagpaparehistro at Pag-login

### 2.1 Pag-login sa System
1. Buksan ang web browser at pumunta sa: `http://localhost/dental-ai-system-fixed/login.php`.
2. Ilagay ang inyong rehistradong **Email / Username** at **Password**.
3. I-click ang **[Sign In]** button.
4. Awtomatikong tutukuyin ng system ang inyong role (Admin, Dentist, o Patient) at dadalhin kayo sa inyong kaukulang dashboard.

![Figure 1: Login Interface](./images/fig_01_login.png)
*Figure 2.1: Opisyal na Login Interface ng Abalos Dental Clinic*

---

### 2.2 Pagpaparehistro ng Bagong Pasyente
1. Sa Login page, i-click ang link na **"Don't have an account? Sign Up"** o pumunta sa `register.php`.
2. Punan ang mga kinakailangang impormasyon:
   - **First Name, Middle Name, Last Name**
   - **Contact Number** (awtomatikong nililimitahan sa 11 digits, hal. `09171234567`)
   - **Email Address** (dito ipadadala ang OTP verification code)
   - **Password at Confirm Password** (may password strength validation)
3. I-click ang **[Create Account]**. Awtomatikong bibigyan ng system ang pasyente ng natatanging **Patient Number** (hal. `PT-2026-0012`).

![Figure 2: Registration Interface](./images/fig_02_register.png)
*Figure 2.2: Patient Registration Form na may live validation*

---

### 2.3 One-Time Password (OTP) Email Verification
- Pagkatapos mag-register, makatatanggap ang pasyente ng 6-digit verification code sa kanyang rehistradong email.
- Ilagay ang 6-digit code sa screen ng `verify_otp.php`.
- Pinipigilan nito ang mga bot, peke, at spam accounts upang masiguro na lehitimo ang bawat pasyente na nagbu-book sa klinika.

---

<div style="page-break-after: always;"></div>

## Kabanata 3: Patient Portal Module

### 3.1 Patient Dashboard
Sa sandaling mag-login ang pasyente, makikita niya ang kanyang dashboard kung saan nakabuod ang kanyang:
- **Upcoming Appointments:** Oras, petsa, dentista, at serbisyo.
- **Appointment Status:** (Pending, Confirmed, Checked In, Serving, Completed, Cancelled).
- **Mabilisang Pindutan:** Pindutan para mag-book ng bagong appointment.

![Figure 3: Patient Dashboard](./images/fig_03_patient_dashboard.png)
*Figure 3.1: Patient Portal Dashboard Overview*

---

### 3.2 Hakbang sa Pag-book ng Appointment
1. Pindutin ang **[Book Appointment]** sa navigation menu o dashboard.
2. Bubukas ang **Interactive Clinic Calendar**:
   - Pumili ng nais na petsa mula Lunes hanggang Sabado (Sarado ang klinika kapag Linggo).
3. Piliin ang **Attending Dentist** (hal. Dr. Abby, Dr. Juan Dela Cruz, atbp.).
4. Piliin ang **Dental Service** (hal. General Dentistry, Orthodontics, Extraction, Pasta, Cleaning).
5. Awtomatikong magpapakita ang system ng mga **Available Time Slots** na may pagitan ng 30 minuto hanggang 1 oras batay sa tagal ng serbisyo.
6. Piliin ang oras at i-click ang **[Confirm Booking]**.

![Figure 4: Booking Calendar](./images/fig_04_patient_calendar.png)
*Figure 3.2: Interactive Booking Calendar na may live slot availability*

> [!NOTE]
> **Tungkol sa Clinic Capacity:**
> Alinsunod sa bagong patakaran ng klinika, tinanggal na ang limitasyon sa kabuuang pasyente bawat araw para sa buong klinika (unlimited capacity). Hangga't may bakanteng oras ang napiling dentista, papayagan ng system ang pag-book.

---

### 3.3 Patient Safety Rules (2 Appointments Per Day Limit)
- **Patakaran:** Ang bawat account ng pasyente ay pinapayagan lamang na mag-book ng maximum na **2 appointments sa iisang araw**.
- **Dahilan (Pang-Defense):**
  1. *Slot Hoarding Prevention:* Upang hindi i-book ng iisang tao ang lahat ng oras sa isang araw.
  2. *Medical Safety:* Upang maiwasan ang sobrang stress o anesthesia complication sa pasyente kapag sumailalim sa higit sa dalawang mabibigat na procedure sa parehong araw.

---

### 3.4 Pamamahala ng Schedule (My Appointments)
- Sa tab na **My Appointments**, makikita ng pasyente ang listahan ng kanyang mga naging appointment.
- Maaaring makita ang eksaktong detalye at mag-request ng appointment update o cancellation kung kinakailangan.

![Figure 5: My Appointments](./images/fig_05_patient_appointments.png)
*Figure 3.3: Talaan ng mga Appointment ng Pasyente*

---

<div style="page-break-after: always;"></div>

## Kabanata 4: Dentist Portal Module

### 4.1 Dentist Dashboard
Ang mga dentista ay may sariling portal upang masubaybayan ang kanilang pasyente nang hindi nakikialam sa pangkalahatang configuration ng klinika.

![Figure 6: Dentist Dashboard](./images/fig_06_dentist_dashboard.png)
*Figure 4.1: Dentist Portal Dashboard*

---

### 4.2 Arawang Listahan ng Pasyente (Dentist Appointments)
- Ipinapakita ng screen na ito ang lahat ng pasyente na nakatakda para sa partikular na doktor sa araw na ito at sa mga darating na araw.
- Malinaw na nakasaad ang **Time Slot**, **Pangalan ng Pasyente**, at **Serbisyong Isasagawa**.

![Figure 7: Dentist Appointments](./images/fig_07_dentist_appointments.png)
*Figure 4.2: Listahan ng mga Pasyente ng Dentista*

---

### 4.3 Live Queue at Dental Consultation Charting
1. Kapag dumating na ang pasyente sa clinic, makikita ito sa **Dentist Queue**.
2. I-click ng dentista ang **[Call / Serving]** kapag papasok na ang pasyente sa dental chair.
3. Pagkatapos ng gamutan, itatala ng dentista ang kanyang clinical notes at diagnosis bago i-click ang **[Complete]**.

![Figure 8: Dentist Queue](./images/fig_08_dentist_queue.png)
*Figure 4.3: Dentist Queue Management Screen*

---

<div style="page-break-after: always;"></div>

## Kabanata 5: Admin Portal Module (Pangunahing Gamit sa Defense)

### 5.1 Admin Dashboard at Clinic Analytics
Ipinapakita sa Admin Dashboard ang buod ng operasyon ng klinika:
- **Kabuuang Appointments Ngayong Araw**
- **Pending Approvals**
- **Aktibong Dentista at Rehistradong Pasyente**
- **Chart ng mga pinaka-in-demand na Dental Services**

![Figure 9: Admin Dashboard](./images/fig_09_admin_dashboard.png)
*Figure 5.1: Administrator Central Dashboard*

---

### 5.2 Appointments Management (Status Actions & Rescheduling)
Sa `admin/appointments.php`, ganap na kontrolado ng Admin ang bawat schedule gamit ang apat (4) na sub-tabs:
1. **Today's Appointments:** Mga appointment na nakatakda sa araw na ito.
2. **Upcoming Appointments:** Mga appointment para sa mga susunod na araw.
3. **Pending Approvals:** Mga bagong request mula sa pasyente na naghihintay ng kumpirmasyon.
4. **Appointment History:** Nakaraang records kasama ang mga completed at cancelled.

![Figure 10: Admin Appointments](./images/fig_10_admin_appointments.png)
*Figure 5.2: Master Appointments Management Interface*

#### Paano Gamitin ang [Change Status] Popup:
1. Hanapin ang appointment at i-click ang **[Change Status]**.
2. May mga pagpipilian:
   - **CONFIRM:** Para aprubahan ang pending na schedule.
   - **CHECK IN:** Kapag dumating na ang pasyente sa clinic lobby.
   - **COMPLETE:** Kapag tapos na ang gamutan.
   - **RESCHEDULE:** Para ilipat ang schedule sa ibang petsa o oras.
   - **CANCEL:** Para kanselahin ang appointment.

---

### 5.3 Dentist Absence & Appointment Reassignment Protocol
> [!IMPORTANT]
> **Ito ang Pangunahing Solusyon sa Tanong ng Panel:**  
> *"Paano kapag absent ang dentista sa araw na may nakatakda siyang appointments?"*

#### Ang Solusyon ng System:
1. **Smart Absence Detection:**  
   Kapag pumasok ang Admin sa `Change Status`, awtomatikong sinusuri ng system kung ang nakatalagang doktor ay minarkahang **Absent** o naka-**Leave** sa petsang iyon sa database.
2. **Kondisyonal na Pagsulpot ng Reassign Button:**
   - **Kapag HINDI absent ang dentista:** HINDI ipapakita ang reassign button upang maiwasan ang pagkakamali ng staff.
   - **Kapag NAKA-MARK AS ABSENT ang doktor:** Awtomatikong lalabas ang button na **[REASSIGN DENTIST]** kalakip ang babala:  
     `⚠️ Assigned dentist is marked ABSENT on this date. Please reassign this appointment to an available doctor.`
3. **Hakbang sa Pag-reassign:**
   - I-click ang **[REASSIGN DENTIST]**.
   - Piliin ang bagong doktor mula sa dropdown list ng mga **Active & Available Dentists**.
   - May built-in conflict check ang system: Kung ang lilipatang doktor ay may appointment na sa oras na iyon o naka-day off din, haharangin ito ng system at hihilingin na pumili ng ibang doktor.
   - I-click ang **[Confirm Reassignment]**. Awtomatikong makatatanggap ng notification ang pasyente at ang bagong doktor.

![Figure 17: Reassign Dentist Modal](./images/fig_17_reassign_dentist_modal.png)
*Figure 5.3: Reassign Dentist Action Popup kapag absent ang doktor*

---

### 5.4 Dentist Schedule Management (Rest Days & Emergency Leave)
Sa pahinang `admin/dentists.php`, makikita ang talaan ng lahat ng lisensyadong dentista sa klinika.

![Figure 11: Dentist Schedule Management](./images/fig_11_admin_dentists.png)
*Figure 5.4: Talaan ng mga Dentista at Pamamahala ng Schedule*

#### A. Pag-marka ng Absent kay Dentista (Emergency / Sick Leave):
1. Hanapin ang dentista at i-click ang pulang button na **[Mark as Absent]**.
2. Sa popup modal:
   - Piliin ang petsa ng pagliban.
   - Piliin ang uri ng leave: *Medical Emergency / Sick Leave*, *Personal / Family Emergency*, o *Official Clinic Duty*.
   - Maglagay ng maikling tala (Notes).
3. I-click ang **[Save Absence Record]**.

![Figure 16: Mark Absent Modal](./images/fig_16_mark_absent_modal.png)
*Figure 5.5: Modal sa Pag-record ng Biglaang Pagliban ng Dentista*

#### B. Pag-alis ng Rest Day / Leave (Bagong Custom Confirmation Modal):
- Kapag pumasok na muli ang dentista o nais bawiin ang rest day, i-click lamang ang **trash icon (basurahan)** sa tabi ng petsa.
- Lalabas ang bagong **Confirmation Modal** (hindi browser alert) na nagpapaliwanag kung sinong doktor at anong petsa ang bubuksan muli para sa mga booking.
- I-click ang pulang **[Remove]** button upang kumpirmahin.

![Figure 18: Delete Rest Day Modal](./images/fig_18_delete_restday_modal.png)
*Figure 5.6: Custom Confirmation Popup bago tanggalin ang rest day o leave*

---

### 5.5 Patient Records Search at Pag-print ng Medical Record
Sa tab na `admin/reports.php` (Tab 2: Patient Reports) at `admin/patients.php`:
1. Gamitin ang live **Search Bar** upang hanapin ang pasyente gamit ang kanyang **Buong Pangalan** o **Patient ID** (hal. `PT-2026-0001`).
2. Agad na sasalain ng system ang listahan sa screen.
3. I-click ang button na **[Print Record]** sa tabi ng pangalan ng pasyente.
4. Bubukas ang opisyal na printable record na naglalaman ng:
   - Clinic Header & Letterhead.
   - Patient Demographics (Edad, Kasarian, Contact, Address).
   - Medical & Dental History.
   - Talaan ng lahat ng naging gamutan, diagnosis, at prescribing dentists.
   - Linya para sa lagda ng Admin at Attending Dentist.

![Figure 12: Patient Records Management](./images/fig_12_admin_patients.png)
*Figure 5.7: Listahan ng mga Pasyente na may Mabilisang Search*

![Figure 15: Patient Medical Record Printout](./images/fig_15_patient_record_print.png)
*Figure 5.8: Opisyal na Printable Dental & Medical Record ng Pasyente*

---

### 5.6 Clinic Reports na may Opisyal na Letterhead
Sa `admin/reports.php`, maaaring mag-generate ng tatlong uri ng ulat ang klinika:
1. **Appointment Reports:** Buod ng lahat ng natapos, nakansela, at no-show na schedule.
2. **Patient Reports:** Demographics at kabuuang bilang ng aktibong pasyente.
3. **Revenue & Treatment Reports:** Kinita ng klinika ayon sa uri ng dental procedure.

![Figure 13: Admin Reports Module](./images/fig_13_admin_reports.png)
*Figure 5.9: Clinic Reports and Analytics Module*

#### Mga Katangian ng Printable Report:
- **Abalos Dental Clinic Official Letterhead:** May pangalan ng klinika, address sa Caloocan City, petsa ng coverage, at petsa kung kailan ito na-print.
- **Smart Printing Layout:** Tanging ang aktibong report tab lamang ang pini-print; awtomatikong itinatago ang top navigation, sidebar, at hamburger buttons upang hindi mag-aksaya ng papel.
- **Official Sign-Offs:** May nakalaang pirmahan sa ibaba para sa *Admin* at *Head Dentist*.

---

### 5.7 Clinic Queue Display System
- Ang `admin/queue.php` ay nagsisilbing live queue board ng klinika.
- Maaari itong i-project sa TV monitor sa reception waiting area ng Abalos Dental Clinic upang makita ng mga pasyente kung sino ang kasalukuyang ginagamot sa bawat dental room.

![Figure 14: Clinic Live Queue Board](./images/fig_14_admin_queue.png)
*Figure 5.10: Central Live Queue Display System*

---

<div style="page-break-after: always;"></div>

## Kabanata 6: Mga Tanong sa Defense at Troubleshooting (Panel FAQ)

### T1: Paano kapag absent ang dentista sa araw na may appointments siya?
> **Sagot:**  
> 1. Pupunta ang Admin sa **Dentists Management** at iki-click ang **[Mark as Absent]** para sa petsang iyon upang ma-block ang calendar sa mga bagong pasyente.  
> 2. Pagkatapos, pupunta ang Admin sa **Appointments**, bubuksan ang **[Change Status]** ng bawat apektadong schedule.  
> 3. Dahil absent ang doktor, awtomatikong magpapakita ang system ng warning at ang button na **[REASSIGN DENTIST]**.  
> 4. Pipili ang Admin ng ibang available na doktor para hawakan ang pasyente. May awtomatikong conflict check ang system upang masigurong hindi magkakasabay ang oras ng bagong doktor. Awtomatikong makatatanggap ng update notification ang pasyente.

### T2: Bakit tinanggal ang clinic daily capacity limit?
> **Sagot:**  
> Ang klinika ay mayroong maraming lisensyadong dentista (hal. 5 doktor) at sariling dental chairs. Ang lumang limit na 10 pasyente bawat araw ay naglilimita sa buong klinika kahit marami pang bakanteng oras ang ibang doktor. Sa bagong version, **unlimited** na ang clinic capacity basta't may bukas na slot ang bawat dentista sa loob ng clinic hours (8:00 AM – 6:00 PM).

### T3: Bakit may limitasyon na 2 appointments per day bawat pasyente?
> **Sagot:**  
> Ito ay isang safety and anti-spam mechanism. Pinipigilan nito ang iisang pasyente na mag-hoard ng napakaraming slot sa parehong araw na magiging dahilan ng pagkaubos ng slots para sa ibang pasyente. Bukod dito, sa dental practice, 1 hanggang 2 procedures lamang ang ligtas na maisagawa sa iisang pasyente sa loob ng isang araw.

### T4: Paano sinisiguro ng system na legal at pormal ang mga printouts?
> **Sagot:**  
> Lahat ng printout—maging Patient Medical Record man o Monthly Clinic Report—ay sumusunod sa standard clinic format na may:
> 1. Opisyal na Letterhead ng Abalos Dental Clinic.
> 2. Kumpletong metadata (Petsa ng pagka-print, coverage date, generated by).
> 3. Dalawahang lagda (Admin Sign-off at Head Dentist Attestation).

---
*Wakas ng User Manual. Binuo para sa Abalos Dental Clinic Capstone Project 2026.*
