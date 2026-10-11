# Building the online forms in Google Forms

Section 3.12 of the manuscript says that the instrument is delivered through
Google Forms. **Two** instruments are provided, one per respondent group of
that section:

| Respondent group | Apps Script | Printed form | Items |
| --- | --- | --- | --- |
| Information Technology experts | `google_apps_script.js` | `IT_Expert_Evaluation_Form.docx` | 38 |
| End-users of the clinic | `google_apps_script_enduser.js` | `EndUser_Evaluation_Form.docx` | 36 |

Use Route 1. It builds the whole form for you.

## Route 1 — build a form with Apps Script (recommended)

Do this once for each of the two scripts.

1. Open <https://script.google.com> and choose **New project**.
2. Delete whatever is in `Code.gs` and paste in the whole of one script from
   this folder.
3. Choose **Run** and pick the function that the script defines:
   * `createItExpertEvaluationForm` for `google_apps_script.js`
   * `createEndUserEvaluationForm` for `google_apps_script_enduser.js`
   
   Google asks for authorisation the first time; the permission is used only
   to create the form in your own Drive, so accept it.
4. Open **View > Logs**. The log prints three addresses:
   * the **edit link**, which is the form as you will see it;
   * the **live link**, which is what you send to the respondents;
   * the destination, which is the response sheet.
5. Open the edit link and check the item count against the table above.
6. Send the live link to the respondents of that group.

The script never edits a form that already exists, so running it twice makes
two forms. Delete the one you do not need.

### What the scripts set

| Setting | IT expert script | End-user script |
| --- | --- | --- |
| Collect email | off | off |
| Limit to one response | off | off |
| Shuffle questions | off | off |
| Rated item type | linear scale 1 to 5 | multiple choice, five numbers plus Not Applicable |
| Labels | 1 = Not Acceptable at All, 5 = Highly Acceptable | the same, with Not Applicable |
| Name field | none | none |

## Why the two forms use different question types

The IT expert script uses a **linear scale**, because a technical respondent
rates every item and no answer needs to be set aside.

The end-user script uses **multiple choice**, because a Google Forms linear
scale cannot carry an extra option and the end-user instrument needs a **Not
Applicable** answer: a patient cannot sensibly rate *"The clinic can easily
update its own information"*. The five numbers are written as bare digits so
that the scoring workbook counts them with `COUNTIF` in exactly the same way
as it counts the linear scale answers. An item answered Not Applicable is left
out of the count N, so it cannot pull a mean down.

## Route 2 — build the forms by hand

If Apps Script is not available, create each form manually and copy the
sections from the printed questionnaire. The structure to reproduce is:

| Section | IT expert form | End-user form |
| --- | --- | --- |
| 1 | Rating scale, then the profile items | Rating scale, then the profile items |
| 2 | Part II introduction | Part II introduction |
| 3 to 10 | One page per characteristic, A to H | One page per characteristic, A to H |
| 11 | Overall assessment and certification | Overall assessment and certification |

Set every rated item to **Required**. On the end-user form, set the six
choices to `5`, `4`, `3`, `2`, `1`, and `Not Applicable`, and write the full
five-point table into the first page description, exactly as `SCALE_HELP` does
in the Apps Script.

## Collecting the answers

1. Open each form and choose **Responses > Link to Sheets**. This creates a
   spreadsheet whose columns are the questions in order.
2. The rated columns are `Q1` onwards, in the order the characteristics
   appear: A, then B, then C, and so on. The IT expert form has `Q1` to
   `Q38`; the end-user form has `Q1` to `Q36`.
3. Paste the rating columns into the matching response sheet of
   `System_Evaluation_Scoring_Sheet.xlsx`:
   * IT expert ratings go on **IT Expert Responses**
   * end-user ratings go on **End User Responses**
   
   One row per respondent. Leave the profile columns out; they are reported
   separately as grouped figures.
4. The mean, summary and comparison sheets then fill themselves in, because
   every figure there is a formula.

## A caution about the responses

The linear scale of the IT expert form and the bare digits of the end-user
form both arrive as numbers, so no recoding is needed. If a form is ever
rebuilt with choices such as `5 - Highly Acceptable`, the answers arrive as
text and must be reduced to the leading digit before they are pasted into the
workbook, otherwise the weighted mean will not compute.

