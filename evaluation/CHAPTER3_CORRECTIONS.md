# Corrections found in Chapter 3 while building the instrument

These were found while the evaluation form was being built from Section 3.12.
Each one is a place where the manuscript, the instrument, or the system do not
agree with one another. None of them needs a new experiment; each is a wording
or numbering repair.

## 1. Section 3.12 states six characteristics, the instrument uses eight

**Where:** Section 3.12, the paragraph that begins *"ISO/IEC 25010 defines
eight product quality characteristics..."*

**What it says now:** of the eight characteristics of the standard, the study
evaluated **six**, namely functional suitability, performance efficiency,
compatibility, reliability, usability and maintainability. Security is
justified as already covered under functional suitability, and portability is
justified as already established by the web architecture.

**Why it is a problem:** the instrument, the ISO/IEC 25010 guideline table,
and every result table of Chapter 4 carry **eight** criteria. Chapter 4
reports a rating for Security and for Portability and an overall rating across
all of them. A panel that compares Section 3.12 with Chapter 4 will see the
two disagree.

**Recommended repair:** change the sentence to state that the study evaluated
all eight product quality characteristics of the standard, and keep the
existing explanation of why the web architecture makes portability
predictable, as a remark about expectations rather than as a reason for
excluding it.

## 2. Section 3.12 points to "Table 18" and to "Table 16" for the same table

**Where:** two consecutive paragraphs of Section 3.12.

**What it says now:** the first paragraph ends *"The criteria and the meaning
of each one are presented in Table 18."* The next paragraph carries the
caption *"Table 16 ISO 25010 Guideline"* and its text also says *"presented in
Table 16 ISO 25010 Guideline."*

**Why it is a problem:** one table cannot be both Table 16 and Table 18.

**Recommended repair:** decide the final number of the ISO/IEC 25010 guideline
table, then use that one number in both paragraphs. Check the sequence of the
other table captions in Section 3.12 at the same time, because the caption of
the guideline table does not follow the caption of the table before it.

## 3. The list of criteria in Section 3.12 includes "Evaluation Design"

**Where:** the paragraph beginning *"The proposed system will be evaluated
based on selected software quality criteria..."*

**What it says now:** the evaluation will focus on Functional Suitability,
Performance Efficiency, Compatibility, Reliability, Usability,
Maintainability, and **Evaluation Design**.

**Why it is a problem:** *evaluation design* is the name of the method used to
run the evaluation, not a quality characteristic of the software that a
respondent can rate. It is described as such in the same table, where it
carries the meaning *"Describes the method used to evaluate the system"*. A
respondent cannot give a five-point rating to a description of the
researcher's own procedure.

**Recommended repair:** remove *Evaluation Design* from the list of rated
criteria and keep it only as a row of explanation, or move that row out of the
table of criteria and into the narrative.

## 4. Tables 13 to 16 of Chapter 4 are missing their last row label

**What is missing:** four of the result tables carry the sub-criteria for
their characteristic and then an empty final row, where the other tables carry
the label **OVERALL RATING** in the *Sub Criterion* column.

| Table | Characteristic | Missing |
| --- | --- | --- |
| Table 13 | Reliability | the words *OVERALL RATING* in the last row |
| Table 14 | Usability | the words *OVERALL RATING* in the last row |
| Table 15 | Maintainability | the words *OVERALL RATING* in the last row |
| Table 16 | Portability | the words *OVERALL RATING* in the last row |

**Recommended repair:** add the label so that all eight tables carry the same
final row.

## 5. The heading of Chapter 4 repeats the heading of Chapter 1

**Where:** the top of Chapter 4.

**What it says now:** the chapter is headed *"CHAPTER 4 / THE PROBLEM AND ITS
BACKGROUND"*, while its first paragraph says *"This chapter presents the
results and discussion..."*.

**Why it is a problem:** *The Problem and Its Background* is the title of
Chapter 1. The heading of Chapter 4 was left as a copy of it.

**Recommended repair:** head the chapter *RESULTS AND DISCUSSION*, matching
the first paragraph and the table of contents.

## 6. Section 3.12 is written in the future tense

**Where:** the *Evaluation Instruments* and *Data Analysis Method* paragraphs.

**What it says now:** *"the researchers will utilize a structured Evaluation
Rating Scale"*, *"The survey will utilize a 5-point Likert Scale"*, *"The data
collected will be analyzed"*.

**Why it is a problem:** the evaluation has already been conducted and its
results are reported in Chapter 4, so the future tense contradicts the chapter
that follows.

**Recommended repair:** change these to the past tense, in the same way that
the rest of Chapter 3 already does (*"were gathered"*, *"was determined"*).

## 7. The forecasting component is described in two different ways

**Where:** Section 3.1 and Section 3.3, against `assets/ai/train_model.py`.

**What the manuscript says:** *"The demand forecasting component was also
implemented using Linear Regression."* and *"The system also uses PHP-based
Linear Regression to analyze historical appointment records and generate a
seven-day appointment demand forecast."*

**What the system contains:**

| File | Method | Purpose |
| --- | --- | --- |
| `admin/demand_forecast.php` | linear regression written in PHP | the seven-day appointment demand forecast shown on the dashboard |
| `assets/ai/train_model.py` | scikit-learn `RandomForestClassifier` | a no-show risk score per patient, trained on the appointment history |

**Why it is a problem:** the manuscript describes only the first of the two.
An IT expert who opens the repository will find a scikit-learn Random Forest
that the manuscript never mentions, and will ask what it is for.

**Recommended repair:** describe both components in Section 3.1 and Section
3.3, and state plainly that the demand forecast is the PHP linear regression
while the Random Forest supplies the no-show risk indicator. The evaluation
form has been written so that the two can be rated separately if the panel
prefers.

## Note on the changes already made to the system

While the instrument was being prepared, five items of the Security section
were repaired in the source, so that the experts rate the delivered system
rather than an earlier one.

| Change | File | Effect |
| --- | --- | --- |
| Session cookie hardened | `config.php` | HttpOnly, SameSite=Lax, and Secure when served over HTTPS |
| Session identifier renewed at sign-in | `login.php` | closes the session fixation weakness |
| Cookie cleared and session destroyed at sign-out | `logout.php` | an old identifier cannot be presented again |
| Database failure no longer printed to the visitor | `config.php` | the technical message goes to the server log instead |
| Anti-forgery token added | `config.php`, 21 pages, 45 forms | a state-changing request without a valid token is refused with HTTP 403 |

If Section 3.13 or Chapter 4 states that a weakness was found, these five
items are the ones to cite, and each repair can be presented as the result of
the review step of the Agile process described in Section 3.1.

The changes were verified by running the pages: the login page and the
forgotten-password page render their token, a sign-in attempt that carries the
token reaches the normal credential check, and a sign-in attempt without the
token is refused with HTTP 403.

