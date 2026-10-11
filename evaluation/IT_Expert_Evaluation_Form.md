# IT Expert Evaluation Form — source of truth

This markdown file is the single source of the printed questionnaire.
Everything above the BEGIN marker is working notes and never reaches the
printed document.

The instrument follows Section 3.12 of the manuscript:

* standard — ISO/IEC 25010 System and Software Quality Models
* respondent group — Information Technology experts (College of Computer
  Studies faculty, members of the capstone panel, licensed IT practitioners)
* instrument — structured evaluation rating scale
* scale — five-point Likert scale, Table 17 of the manuscript
* treatment — weighted mean

## Notes for the researchers (not part of the printed form)

Characteristics evaluated: eight, namely functional suitability, performance
efficiency, compatibility, reliability, usability, security, maintainability,
and portability. Section 3.12 of the manuscript states that six were
evaluated; the instrument, Table 3 of the manuscript, and Tables 9 to 16 of
Chapter 4 all carry eight, so the sentence in 3.12 should be corrected to
agree with the instrument.

Item count per characteristic: 5, 5, 4, 5, 4, 6, 5, 4, for a total of 38
rated items.

Weighted mean of one item:

    W = SUM(F * X) / N

where F is the number of respondents who chose a given scale value, X is that
scale value, and N is the total number of respondents.

Weighted mean of one characteristic: the mean of the weighted means of its
items, or equivalently the sum of all the ratings given to that characteristic
divided by the number of ratings received.

Grand mean: the mean of the eight characteristic means, with every
characteristic carrying the same weight.

Interpretation follows Table 2 of this instrument, which reproduces Table 17
of the manuscript.

Build the printable document:

    .venv\Scripts\python.exe evaluation\gen_it_eval_docx.py

Build the scoring workbook:

    .venv\Scripts\python.exe evaluation\gen_it_eval_xlsx.py

<!-- BEGIN SUBMITTABLE -->

# SYSTEM ACCEPTABILITY EVALUATION FORM

## FOR INFORMATION TECHNOLOGY (IT) EXPERT RESPONDENTS

**Table 1. System Under Evaluation**

| Particulars | Details |
| --- | --- |
| Name of System | AI-Based Intelligent Dental Appointment Scheduling System Using Machine Learning for Optimized Clinic Management |
| Client | Abalos Dental Clinic |
| Proponents | Tagudar, et al. |
| Instrument Used | Adapted from the ISO/IEC 25010 System and Software Quality Models |
| Respondent Group | Information Technology (IT) experts |

## INSTRUCTIONS TO THE RESPONDENT

This questionnaire measures the acceptability of the system described above.
You were selected as an IT expert respondent because of your background in
software development, systems analysis, or system evaluation. Kindly access
the system and perform at least one task in it before answering, so that every
rating is based on direct observation rather than on description alone. There
is no right or wrong answer. Answer the items honestly according to the
behaviour that you actually observed. Participation is voluntary, and the
form is answered without a name, so no answer can be traced back to you.

## RATING SCALE

Read each indicator and encircle the number that corresponds to your
assessment of the system.

**Table 2. Five-Point Likert Scale**

| Scale | Range | Verbal Interpretation | Meaning |
| --- | --- | --- | --- |
| 5 | 4.21 to 5.00 | Highly Acceptable | The attribute is fully present and no improvement is needed. |
| 4 | 3.41 to 4.20 | Acceptable | The attribute is present and works as intended, with only minor improvement needed. |
| 3 | 2.61 to 3.40 | Moderately Acceptable | The attribute is present but shows a noticeable weakness that should be corrected. |
| 2 | 1.81 to 2.60 | Not Acceptable | The attribute is partially present or unreliable and needs substantial correction. |
| 1 | 1.00 to 1.80 | Not Acceptable at All | The attribute is absent or does not work. |

## PART I. RESPONDENT PROFILE

This part is answered without a name. Only grouped figures are reported.

**Table 3. Affiliation of the Respondent**

| Affiliation | Check |
| --- | --- |
| College of Computer Studies Faculty |  |
| Member of the Capstone Panel |  |
| Licensed IT Practitioner |  |
| Software Developer or Programmer |  |
| Other (please specify): ______________________________ |  |

**Table 4. Profile Items**

| Item | Response |
| --- | --- |
| 1. Number of years of experience in software development, systems analysis, or information technology | ________ year(s) |
| 2. Have you accessed the system and performed at least one task in it? | Yes ______  No ______ |
| 3. If No, kindly access the system first, then answer this form. |  |

If you answered No, please stop here and return the form after accessing the
system.

**Table 5. Familiarity with the Technologies Used by the System**

| Technology | Very Familiar | Familiar | Somewhat Familiar | Not Familiar |
| --- | --- | --- | --- | --- |
| PHP server-side programming |  |  |  |  |
| MySQL or MariaDB database |  |  |  |  |
| HTML5, CSS3, JavaScript, Bootstrap |  |  |  |  |
| Statistical or machine-learning forecasting |  |  |  |  |
| Web application security testing |  |  |  |  |

**Table 6. Modules Tested by the Respondent**

| Module | Check |
| --- | --- |
| Account registration and login |  |
| Patient information management |  |
| Dentist information and schedule management |  |
| Appointment booking, validation, and conflict checking |  |
| Queue management and QR walk-in check-in |  |
| Notifications and appointment reminders |  |
| Reports and dashboard monitoring |  |
| Seven-day appointment demand forecast |  |
| User account and clinic settings |  |

## PART II. EVALUATION OF THE SYSTEM

The items below are grouped according to the quality characteristics of
ISO/IEC 25010. The wording of each item has been adjusted for a technical
respondent, so the items ask about the behaviour of the software rather than
about the convenience of the clinic staff. Encircle the number that
corresponds to your assessment.

### A. FUNCTIONAL SUITABILITY

The degree to which the system provides functions that meet the stated
requirements when used under the specified conditions.

**Table 7. Functional Suitability**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | The system provides the functions stated in the objectives of the study: appointment scheduling, patient records, dentist and schedule management, queue management, notifications, reports, and demand forecasting. |  |  |  |  |  |
| 2 | The implemented functions agree with the processes documented in the context diagram, the data flow diagrams, and the activity diagrams of Chapter 3. |  |  |  |  |  |
| 3 | The appointment validation correctly refuses a booking when the dentist is unavailable, when the slot is already taken, or when the requested date is not valid. |  |  |  |  |  |
| 4 | The access of each respondent is limited to the functions of that respondent's role, namely administrator, dentist, or patient. |  |  |  |  |  |
| 5 | Every function produces the output that the requirement specifies, including the refusal message when an appointment cannot be accepted. |  |  |  |  |  |

### B. PERFORMANCE EFFICIENCY

The degree to which the system performs its functions within the response
times and resource limits expected in the clinic.

**Table 8. Performance Efficiency**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Pages load within an acceptable time during normal clinic use. |  |  |  |  |  |
| 2 | The appointment validation returns its result without a delay that the respondent notices. |  |  |  |  |  |
| 3 | The seven-day appointment demand forecast is generated without a delay that the respondent notices. |  |  |  |  |  |
| 4 | The system continues to respond acceptably when several users use it at the same time. |  |  |  |  |  |
| 5 | The database work of the system is efficient, in that routine actions do not repeat the same query unnecessarily or scan a whole table to answer a single record. |  |  |  |  |  |

### C. COMPATIBILITY

The degree to which the system operates correctly with the devices, browsers,
and software in the environment described in Section 3.3.

**Table 9. Compatibility**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | The system runs on the PHP, MySQL, and web server environment described in Section 3.3 without requiring additional software. |  |  |  |  |  |
| 2 | The interface displays correctly in the current versions of the common browsers, namely Chrome, Edge, and Firefox. |  |  |  |  |  |
| 3 | The system works on both a desktop browser and a mobile browser without breaking its layout or its functions. |  |  |  |  |  |
| 4 | The system uses standard web technologies, namely HTML5, CSS3, JavaScript, and Bootstrap, without components that conflict with one another. |  |  |  |  |  |

### D. RELIABILITY

The degree to which the system performs its functions without failure and
keeps the stored data correct during normal operation.

**Table 10. Reliability**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | The system operates without unexpected errors or interruptions during continuous use. |  |  |  |  |  |
| 2 | Records are neither lost nor duplicated when a form is submitted, including when the submit control is pressed more than once. |  |  |  |  |  |
| 3 | Two requests for the same appointment slot are handled so that only one booking is accepted and the other is refused. |  |  |  |  |  |
| 4 | Error conditions produce an understandable message instead of a system error page, a blank screen, or raw technical detail. |  |  |  |  |  |
| 5 | The data a module writes is the same data another module reads, so that an update made by the dentist is seen at once by the patient. |  |  |  |  |  |

### E. USABILITY

The degree to which the system can be understood, learned, and operated by its
users, including the clarity of what the system is doing at each step.

**Table 11. Usability**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | The navigation makes it clear at all times which module and which role the respondent is currently using. |  |  |  |  |  |
| 2 | Forms identify their required fields, and invalid input is refused with a message that tells the respondent what to correct. |  |  |  |  |  |
| 3 | Labels, layout, and terminology are consistent across the administrator, dentist, and patient interfaces. |  |  |  |  |  |
| 4 | A respondent can complete a typical task, such as booking, confirming, joining the queue, or rescheduling, without referring to external documentation. |  |  |  |  |  |

### F. SECURITY

The degree to which the system protects the data it holds and the accounts
that use it, and the degree to which it resists requests that the system did
not intend to receive.

**Table 12. Security**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Passwords are stored as one-way hashes, not as readable text and not through an obsolete digest such as MD5 or SHA1. |  |  |  |  |  |
| 2 | The system prevents SQL injection by sending user input as bound parameters instead of joining the input into the SQL statement. |  |  |  |  |  |
| 3 | The system encodes user-supplied values before displaying them, so that script injection (cross-site scripting) is prevented. |  |  |  |  |  |
| 4 | A form that changes data carries an anti-forgery token, and a submission without a valid token is refused. |  |  |  |  |  |
| 5 | The session identifier is renewed when a respondent signs in, and it is discarded when the respondent signs out. |  |  |  |  |  |
| 6 | Error and failure messages do not disclose database names, file paths, credentials, or other internal detail to the respondent. |  |  |  |  |  |

### G. MAINTAINABILITY

The degree to which the system can be corrected, updated, and extended without
introducing defects elsewhere.

**Table 13. Maintainability**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | The source is organised into modules that correspond to the processes of Chapter 3, so that a change is made in one place rather than repeated in several. |  |  |  |  |  |
| 2 | The code is readable through consistent naming, consistent indentation, and one clear responsibility per file or function. |  |  |  |  |  |
| 3 | Configuration values, such as the database connection and the clinic settings, are separated from the application logic. |  |  |  |  |  |
| 4 | Section headers and comments state the purpose of each handler and each function. |  |  |  |  |  |
| 5 | The database is normalised, so that a record is stored once and referred to elsewhere rather than copied into several tables. |  |  |  |  |  |

### H. PORTABILITY

The degree to which the system can be moved to another environment without
being rewritten.

**Table 14. Portability**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | The system can be deployed to another PHP and MySQL host by changing configuration only, without rewriting the source. |  |  |  |  |  |
| 2 | The system requires no installation on the client device, because it is used entirely through a browser. |  |  |  |  |  |
| 3 | The database can be recreated from the accompanying SQL file, so the system can be restored on a new host. |  |  |  |  |  |
| 4 | The system works both on the XAMPP environment used by the researchers and on a comparable PHP hosting environment. |  |  |  |  |  |

## PART III. SUMMARY OF RATINGS

This part is completed after all items have been answered. The weighted mean
of each characteristic is the average of the ratings given to its items.

**Table 15. Summary of Ratings by Quality Characteristic**

| Quality Characteristic | Number of Items | Weighted Mean | Verbal Interpretation |
| --- | --- | --- | --- |
| A. Functional Suitability | 5 |  |  |
| B. Performance Efficiency | 5 |  |  |
| C. Compatibility | 4 |  |  |
| D. Reliability | 5 |  |  |
| E. Usability | 4 |  |  |
| F. Security | 6 |  |  |
| G. Maintainability | 5 |  |  |
| H. Portability | 4 |  |  |
| **Overall System Rating** | **38** |  |  |

## PART IV. OVERALL ASSESSMENT AND FINDINGS

**Table 16. Overall Assessment**

| Item | Response |
| --- | --- |
| 1. Overall rating of the system | ________ |
| 2. The strongest quality characteristic of the system | ______________________________ |
| 3. The quality characteristic that most needs improvement | ______________________________ |
| 4. Defects, errors, or unexpected behaviour observed (state the page and the steps taken) | ______________________________ |
| 5. Recommended improvements, in order of priority | ______________________________ |
| 6. Other comments or suggestions | ______________________________ |

## PART V. CERTIFICATION

I certify that I accessed the system described in this instrument, that I
performed at least one task in it, and that the ratings recorded above are my
own assessment based on the behaviour I observed.

**Table 17. Certification**

| Particulars | Details |
| --- | --- |
| Signature | ______________________________ |
| Printed Name (optional) | ______________________________ |
| Designation or Affiliation | ______________________________ |
| Date Accomplished | ______________________________ |

<!-- END SUBMITTABLE -->


