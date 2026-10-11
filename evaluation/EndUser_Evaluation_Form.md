# End-User Evaluation Form — source of truth

This markdown file is the single source of the printed questionnaire.
Everything above the BEGIN marker is working notes and never reaches the
printed document.

The instrument follows Section 3.12 of the manuscript and covers the second
respondent group of that section:

* respondent group — end-users of the clinic, namely the clinic administrator
  or receptionist, the dentist or dentists using the system, and the patients
* instrument — structured evaluation rating scale
* scale — five-point Likert scale, Table 17 of the manuscript, plus a Not
  Applicable column so that a respondent is not forced to rate an item that
  does not apply to that respondent's role
* treatment — weighted mean

## Notes for the researchers (not part of the printed form)

Characteristics evaluated: eight, namely functional suitability, performance
efficiency, compatibility, reliability, usability, security, maintainability,
and portability, so that this instrument matches Table 3 and Tables 9 to 16
of Chapter 4.

Item count per characteristic: 5, 5, 4, 5, 4, 5, 4, 4, for a total of 36
rated items.

Differences from the earlier Google Form that was circulated:

* the Name field was removed, because Section 3.13 states that the
  questionnaires were answered without names and that no respondent is named
  anywhere in the study;
* Year and Course was kept but made optional, since it is a grouped figure
  rather than an identifying one;
* the role options were corrected to the three roles of Section 3.12, and the
  IT expert option was removed, because that group answers the other
  instrument;
* the agreement labels (Strong Disagree to Strongly Agree) were replaced with
  the acceptability labels of Table 17, so that a mean may be reported as
  Highly Acceptable;
* a Not Applicable column was added to every item;
* Security and Portability were added, because the earlier form carried only
  six characteristics;
* an access question and an overall rating question were added.

Treatment of Not Applicable: an item answered Not Applicable is left out of
the tally for that item. The number of responses N in the workbook therefore
counts only the ratings from 1 to 5, and the weighted mean of an item divides
by that count rather than by the number of respondents. This matches the
treatment of a missing response described in Section 3.13.

Build the printable document:

    .venv\Scripts\python.exe evaluation\gen_enduser_docx.py

<!-- BEGIN SUBMITTABLE -->

# SYSTEM EVALUATION FORM

## FOR END-USER RESPONDENTS

**Table 1. System Under Evaluation**

| Particulars | Details |
| --- | --- |
| Name of System | AI-Based Intelligent Dental Appointment Scheduling System Using Machine Learning for Optimized Clinic Management |
| Client | Abalos Dental Clinic |
| Proponents | Tagudar, et al. |
| Instrument Used | Adapted from the ISO/IEC 25010 System and Software Quality Models |
| Respondent Group | End-users: clinic administrator or receptionist, dentist, and patient |

## INSTRUCTIONS TO THE RESPONDENT

Please rate each statement based on your experience of using the system. This
form is answered **without your name**, and the answers are reported only as
grouped scores, so no answer can be traced back to you. There is no right or
wrong answer. Please answer according to what you actually experienced while
using the system.

Kindly use the system and finish at least one task before you answer, so that
every rating rests on real use rather than on a description of the system.

## RATING SCALE

Read each statement and choose the number that matches your experience.

**Table 2. Five-Point Likert Scale**

| Scale | Range | Verbal Interpretation | Meaning |
| --- | --- | --- | --- |
| 5 | 4.21 to 5.00 | Highly Acceptable | I am fully satisfied and nothing needs to change. |
| 4 | 3.41 to 4.20 | Acceptable | It works as I expect, with only a small improvement needed. |
| 3 | 2.61 to 3.40 | Moderately Acceptable | It works, but there is a weakness that should be corrected. |
| 2 | 1.81 to 2.60 | Not Acceptable | It works only partly or not reliably, and needs a large correction. |
| 1 | 1.00 to 1.80 | Not Acceptable at All | It does not work, or it is missing. |
| N/A | — | Not Applicable | The statement does not apply to my role or to the task I performed. |

Choose **N/A** only when the statement genuinely does not apply to you, not
when you are unsure. A statement answered N/A is left out of the computation.

## PART I. RESPONDENT PROFILE

This part is answered without a name.

**Table 3. Role in This Evaluation**

| Role | Check |
| --- | --- |
| Clinic administrator or receptionist |  |
| Dentist |  |
| Patient |  |

**Table 4. Profile Items**

| Item | Response |
| --- | --- |
| 1. Have you used the system and finished at least one task in it? | Yes ______  No ______ |
| 2. How many times have you used the system? | ________ time(s) |
| 3. How many times have you visited the dental clinic? | ________ time(s) |
| 4. Year and course (optional; leave blank if you prefer) | ____________________ |

If you answered No to item 1, please use the system first and then answer this
form.

**Table 5. How Did You Get Your Appointment on the Day of Your Visit?**

| Way of booking | Check |
| --- | --- |
| I scanned the QR code and walked in |  |
| I booked online using the system |  |
| The receptionist booked it for me |  |
| I called the clinic |  |
| I did not book; I walked in without an appointment |  |

**Table 6. Which Device Did You Use to Access the System?**

| Device | Check |
| --- | --- |
| Mobile phone |  |
| Tablet |  |
| Laptop |  |
| Computer |  |
| More than one of the above |  |

**Table 7. Which Parts of the System Did You Use?**

| Part of the system | Check |
| --- | --- |
| Signing in or creating an account |  |
| Booking an appointment |  |
| Viewing or managing my appointments |  |
| Confirming my attendance or sending a concern |  |
| Reading notifications or reminders |  |
| The patient queue, including the QR walk-in page |  |
| Managing patients and appointments |  |
| Managing dentists, schedules, or clinic settings |  |
| Viewing reports or the dashboard |  |

## PART II. EVALUATION OF THE SYSTEM

The statements are grouped according to the quality characteristics of
ISO/IEC 25010. Read each statement and choose the number that matches your
experience, or N/A when the statement does not apply to your role.

### A. FUNCTIONAL SUITABILITY

The degree to which the system gives you the functions you need, and gives
them correctly.

**Table 8. Functional Suitability**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 | N/A |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | I can complete the appointment task I need to do in the system. |  |  |  |  |  |  |
| 2 | The system correctly saves the information I enter. |  |  |  |  |  |  |
| 3 | The system shows me the correct appointment details, such as the date, the time, the dentist, and the service. |  |  |  |  |  |  |
| 4 | The system tells me when my chosen schedule is not available instead of accepting it anyway. |  |  |  |  |  |  |
| 5 | I can use only the functions that belong to my role, and not the functions that belong to another role. |  |  |  |  |  |  |

### B. PERFORMANCE EFFICIENCY

The degree to which the system answers you quickly and does not keep you
waiting.

**Table 9. Performance Efficiency**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 | N/A |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | The pages of the system open quickly. |  |  |  |  |  |  |
| 2 | Completing a task, such as booking or updating an appointment, takes only a short time. |  |  |  |  |  |  |
| 3 | The system stays fast even when many appointments are already stored. |  |  |  |  |  |  |
| 4 | The information I need appears right away, without a long wait. |  |  |  |  |  |  |
| 5 | Booking or rescheduling my appointment takes only a short time. |  |  |  |  |  |  |

### C. COMPATIBILITY

The degree to which the system works on the device and the browser you
actually use.

**Table 10. Compatibility**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 | N/A |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | The system works properly on the device I use. |  |  |  |  |  |  |
| 2 | The system works properly in the web browser I use. |  |  |  |  |  |  |
| 3 | The text and the images on my screen are clear and easy to read. |  |  |  |  |  |  |
| 4 | The system works on a mobile phone, a tablet, and a computer. |  |  |  |  |  |  |

### D. RELIABILITY

The degree to which the system works correctly and does not lose what you
have entered.

**Table 11. Reliability**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 | N/A |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | The system works correctly every time I use it. |  |  |  |  |  |  |
| 2 | The system does not show errors during normal use. |  |  |  |  |  |  |
| 3 | The information I enter is saved correctly and is not lost. |  |  |  |  |  |  |
| 4 | The system is available whenever I need to use it. |  |  |  |  |  |  |
| 5 | When I sign in again, the information I saved earlier is still there. |  |  |  |  |  |  |

### E. USABILITY

The degree to which the system is easy to learn, easy to understand, and easy
to find your way around.

**Table 12. Usability**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 | N/A |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | The system is easy to learn, even for a first-time user. |  |  |  |  |  |  |
| 2 | The labels, the buttons, and the instructions in the system are clear and easy to understand. |  |  |  |  |  |  |
| 3 | I can find the page or the information I need without difficulty. |  |  |  |  |  |  |
| 4 | The system gives a clear and helpful message when something is unavailable, or when I enter something wrong. |  |  |  |  |  |  |

### F. SECURITY

The degree to which the system keeps your information private and keeps other
people from acting on your behalf.

**Table 13. Security**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 | N/A |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | My password is never shown to anyone, not even to the clinic staff. |  |  |  |  |  |  |
| 2 | I can see my own information, but I do not see the information of other patients. |  |  |  |  |  |  |
| 3 | The system does not let me change or remove anything that belongs to another person. |  |  |  |  |  |  |
| 4 | When something goes wrong, the system gives a short explanation instead of technical details. |  |  |  |  |  |  |
| 5 | Nobody can make me submit a form or change my information without my knowing. |  |  |  |  |  |  |

### G. MAINTAINABILITY

The degree to which the system and the information in it can be updated
easily when something changes.

**Table 14. Maintainability**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 | N/A |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | The clinic can easily update its own information, such as the services offered and the opening hours. |  |  |  |  |  |  |
| 2 | My own information, such as my schedule or my personal details, is easy to update when it changes. |  |  |  |  |  |  |
| 3 | When I sign out, the next person cannot continue using the system as me on the same device. |  |  |  |  |  |  |
| 4 | The information already in the system does not disappear or become wrong when the system is updated. |  |  |  |  |  |  |

### H. PORTABILITY

The degree to which you can reach the system from whatever device you have,
without extra work.

**Table 15. Portability**

| No. | Sub-criterion | 5 | 4 | 3 | 2 | 1 | N/A |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | I can open the system from any device that has a modern browser, without installing any program. |  |  |  |  |  |  |
| 2 | I did not have to install anything on my phone or on my computer to use the system. |  |  |  |  |  |  |
| 3 | The system works the same way whether I use a phone, a tablet, or a computer. |  |  |  |  |  |  |
| 4 | I can scan the QR code and reach the walk-in page from my phone wherever I am. |  |  |  |  |  |  |

## PART III. SUMMARY OF RATINGS

This part is completed after all the statements have been answered. The
weighted mean of each characteristic is the average of the ratings given to
its statements. Statements answered N/A are left out.

**Table 16. Summary of Ratings by Quality Characteristic**

| Quality Characteristic | Number of Items | Weighted Mean | Verbal Interpretation |
| --- | --- | --- | --- |
| A. Functional Suitability | 5 |  |  |
| B. Performance Efficiency | 5 |  |  |
| C. Compatibility | 4 |  |  |
| D. Reliability | 5 |  |  |
| E. Usability | 4 |  |  |
| F. Security | 5 |  |  |
| G. Maintainability | 4 |  |  |
| H. Portability | 4 |  |  |
| **Overall System Rating** | **36** |  |  |

## PART IV. OVERALL ASSESSMENT AND FINDINGS

**Table 17. Overall Assessment**

| Item | Response |
| --- | --- |
| 1. Overall rating of the system as a whole (5, 4, 3, 2, or 1) | ________ |
| 2. The strongest quality characteristic of the system | ______________________________ |
| 3. The quality characteristic that most needs improvement | ______________________________ |
| 4. Was there anything in the system that did not work, or that you could not find? Please describe it. | ______________________________ |
| 5. What should be improved first? Please give the change you would like. | ______________________________ |
| 6. Other comments or suggestions | ______________________________ |

## PART V. CERTIFICATION

I certify that I used the system described in this instrument, that I finished
at least one task in it, and that the ratings above are my own assessment
based on my experience.

**Table 18. Certification**

| Particulars | Details |
| --- | --- |
| Signature (optional) | ______________________________ |
| Designation or role | ______________________________ |
| Date Accomplished | ______________________________ |

<!-- END SUBMITTABLE -->




