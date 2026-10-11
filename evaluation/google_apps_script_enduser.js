/**
 * Build the end-user evaluation form in Google Forms.
 *
 * HOW TO RUN
 *   1. Open https://script.google.com and choose New project.
 *   2. Replace the contents of Code.gs with this whole file.
 *   3. Choose Run > createEndUserEvaluationForm and grant the permission
 *      that Google asks for. The permission is needed only to create the
 *      form in your own Drive.
 *   4. Open View > Logs. The log prints the edit link and the live link of
 *      the new form.
 *
 * WHY EACH RATED ITEM IS A MULTIPLE-CHOICE ITEM
 *   A Google Forms linear scale cannot carry an extra option, and this
 *   instrument needs a "Not Applicable" answer so that a patient is not
 *   forced to rate a statement that describes the administrator's work.
 *   The choices are therefore the five numbers plus "Not Applicable", and
 *   the five numbers are written as bare digits so that the scoring
 *   workbook can count them with COUNTIF exactly as it counts the linear
 *   scale answers of the IT expert instrument.
 *
 * Running the script again creates a second form. It never edits a form
 * that already exists.
 */

var FORM_TITLE = 'System Evaluation Form - For End-User Respondents';

var SYSTEM_NAME =
  'AI-Based Intelligent Dental Appointment Scheduling System Using ' +
  'Machine Learning for Optimized Clinic Management';

var INSTRUCTIONS =
  'Please rate each statement based on your experience of using the ' +
  'system. This form is answered without your name, and the answers are ' +
  'reported only as grouped scores, so no answer can be traced back to you. ' +
  'There is no right or wrong answer. Kindly use the system and finish at ' +
  'least one task before you answer, so that every rating rests on real use ' +
  'rather than on a description of the system.\n' +
  '\n' +
  'SYSTEM: ' + SYSTEM_NAME + '\n' +
  'CLIENT: Abalos Dental Clinic\n' +
  '\n' +
  '5 = Highly Acceptable    4 = Acceptable    3 = Moderately Acceptable\n' +
  '2 = Not Acceptable       1 = Not Acceptable at All\n' +
  'Not Applicable = the statement does not apply to my role or my task';

var NO_ANSWER = 'Not Applicable';
var CHOICES = ['5', '4', '3', '2', '1', NO_ANSWER];

var SCALE_HELP =
  '5 = 4.21 to 5.00  Highly Acceptable - I am fully satisfied and nothing ' +
  'needs to change.\n' +
  '4 = 3.41 to 4.20  Acceptable - It works as I expect, with only a small ' +
  'improvement needed.\n' +
  '3 = 2.61 to 3.40  Moderately Acceptable - It works, but there is a ' +
  'weakness that should be corrected.\n' +
  '2 = 1.81 to 2.60  Not Acceptable - It works only partly or not ' +
  'reliably, and needs a large correction.\n' +
  '1 = 1.00 to 1.80  Not Acceptable at All - It does not work, or it is ' +
  'missing.\n' +
  'Not Applicable - the statement does not apply to my role or to the task ' +
  'I performed. Please choose this only when the statement genuinely does ' +
  'not apply, not when you are unsure. A statement answered Not Applicable ' +
  'is left out of the computation.';

var CHARACTERISTICS = [
  {
    code: 'A',
    title: 'A. Functional Suitability',
    help: 'The degree to which the system gives you the functions you need, ' +
          'and gives them correctly.',
    items: [
      'I can complete the appointment task I need to do in the system.',
      'The system correctly saves the information I enter.',
      'The system shows me the correct appointment details, such as the ' +
        'date, the time, the dentist, and the service.',
      'The system tells me when my chosen schedule is not available ' +
        'instead of accepting it anyway.',
      'I can use only the functions that belong to my role, and not the ' +
        'functions that belong to another role.'
    ]
  },
  {
    code: 'B',
    title: 'B. Performance Efficiency',
    help: 'The degree to which the system answers you quickly and does not ' +
          'keep you waiting.',
    items: [
      'The pages of the system open quickly.',
      'Completing a task, such as booking or updating an appointment, ' +
        'takes only a short time.',
      'The system stays fast even when many appointments are already ' +
        'stored.',
      'The information I need appears right away, without a long wait.',
      'Booking or rescheduling my appointment takes only a short time.'
    ]
  },
  {
    code: 'C',
    title: 'C. Compatibility',
    help: 'The degree to which the system works on the device and the ' +
          'browser you actually use.',
    items: [
      'The system works properly on the device I use.',
      'The system works properly in the web browser I use.',
      'The text and the images on my screen are clear and easy to read.',
      'The system works on a mobile phone, a tablet, and a computer.'
    ]
  },
  {
    code: 'D',
    title: 'D. Reliability',
    help: 'The degree to which the system works correctly and does not ' +
          'lose what you have entered.',
    items: [
      'The system works correctly every time I use it.',
      'The system does not show errors during normal use.',
      'The information I enter is saved correctly and is not lost.',
      'The system is available whenever I need to use it.',
      'When I sign in again, the information I saved earlier is still ' +
        'there.'
    ]
  },
  {
    code: 'E',
    title: 'E. Usability',
    help: 'The degree to which the system is easy to learn, easy to ' +
          'understand, and easy to find your way around.',
    items: [
      'The system is easy to learn, even for a first-time user.',
      'The labels, the buttons, and the instructions in the system are ' +
        'clear and easy to understand.',
      'I can find the page or the information I need without difficulty.',
      'The system gives a clear and helpful message when something is ' +
        'unavailable, or when I enter something wrong.'
    ]
  },
  {
    code: 'F',
    title: 'F. Security',
    help: 'The degree to which the system keeps your information private ' +
          'and keeps other people from acting on your behalf.',
    items: [
      'My password is never shown to anyone, not even to the clinic staff.',
      'I can see my own information, but I do not see the information of ' +
        'other patients.',
      'The system does not let me change or remove anything that belongs ' +
        'to another person.',
      'When something goes wrong, the system gives a short explanation ' +
        'instead of technical details.',
      'Nobody can make me submit a form or change my information without ' +
        'my knowing.'
    ]
  },
  {
    code: 'G',
    title: 'G. Maintainability',
    help: 'The degree to which the system and the information in it can be ' +
          'updated easily when something changes.',
    items: [
      'The clinic can easily update its own information, such as the ' +
        'services offered and the opening hours.',
      'My own information, such as my schedule or my personal details, is ' +
        'easy to update when it changes.',
      'When I sign out, the next person cannot continue using the system ' +
        'as me on the same device.',
      'The information already in the system does not disappear or become ' +
        'wrong when the system is updated.'
    ]
  },
  {
    code: 'H',
    title: 'H. Portability',
    help: 'The degree to which you can reach the system from whatever ' +
          'device you have, without extra work.',
    items: [
      'I can open the system from any device that has a modern browser, ' +
        'without installing any program.',
      'I did not have to install anything on my phone or on my computer to ' +
        'use the system.',
      'The system works the same way whether I use a phone, a tablet, or a ' +
        'computer.',
      'I can scan the QR code and reach the walk-in page from my phone ' +
        'wherever I am.'
    ]
  }
];

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/**
 * Add one rated statement: the five numbers plus "Not Applicable".
 * The numbers are bare digits so that COUNTIF can count them directly.
 */
function addRated(form, text) {
  var item = form.addMultipleChoiceItem();
  item.setTitle(text);
  item.setChoiceValues(CHOICES);
  item.setRequired(true);
  return item;
}

function addChoice(form, title, options, required) {
  var item = form.addMultipleChoiceItem();
  item.setTitle(title);
  item.setChoiceValues(options);
  if (required) {
    item.setRequired(true);
  }
  return item;
}

function addText(form, title, required) {
  var item = form.addTextItem();
  item.setTitle(title);
  if (required) {
    item.setRequired(true);
  }
  return item;
}

function addParagraph(form, title, required) {
  var item = form.addParagraphTextItem();
  item.setTitle(title);
  if (required) {
    item.setRequired(true);
  }
  return item;
}

function addCheckboxes(form, title, options, required) {
  var item = form.addCheckboxItem();
  item.setTitle(title);
  item.setChoiceValues(options);
  if (required) {
    item.setRequired(true);
  }
  return item;
}

/* ------------------------------------------------------------------ */
/* The form                                                            */
/* ------------------------------------------------------------------ */

function createEndUserEvaluationForm() {
  var form = FormApp.create(FORM_TITLE);

  // The instrument is answered without a name, so the email is not
  // collected and no identifying field is asked for. Year and course is
  // offered but left optional, because it is a grouped figure rather than
  // an identifying one.
  form.setDescription(INSTRUCTIONS);
  form.setCollectEmail(false);
  form.setAllowResponseEdits(false);
  form.setShuffleQuestions(false);
  form.setLimitOneResponsePerUser(false);

  form.addSectionHeaderItem()
    .setTitle('Rating Scale')
    .setHelpText(SCALE_HELP);

  addChoice(form, '1. Your role in this evaluation', [
    'Clinic administrator or receptionist',
    'Dentist',
    'Patient'
  ], true);

  addChoice(form,
    '2. Have you used the system and finished at least one task in it? ' +
    'If No, please use the system first and then answer this form.',
    ['Yes', 'No'], true);

  addText(form, '3. How many times have you used the system?', true);
  addText(form, '4. How many times have you visited the dental clinic?',
          true);
  addText(form,
    '5. Year and course (optional; leave blank if you prefer to)', false);

  addCheckboxes(form,
    '6. How did you get your appointment on the day of your visit? ' +
    'Check all that apply.',
    [
      'I scanned the QR code and walked in',
      'I booked online using the system',
      'The receptionist booked it for me',
      'I called the clinic',
      'I did not book; I walked in without an appointment'
    ], false);

  addChoice(form, '7. Which device did you use to access the system?', [
    'Mobile phone',
    'Tablet',
    'Laptop',
    'Computer',
    'More than one of the above'
  ], true);

  addCheckboxes(form, '8. Which parts of the system did you use? ' +
                      'Check all that apply.',
    [
      'Signing in or creating an account',
      'Booking an appointment',
      'Viewing or managing my appointments',
      'Confirming my attendance or sending a concern',
      'Reading notifications or reminders',
      'The patient queue, including the QR walk-in page',
      'Managing patients and appointments',
      'Managing dentists, schedules, or clinic settings',
      'Viewing reports or the dashboard'
    ], true);

  form.addPageBreakItem()
    .setTitle('Part II. Evaluation of the System')
    .setHelpText('The statements are grouped according to the quality ' +
                 'characteristics of ISO/IEC 25010. Choose the number that ' +
                 'matches your experience, or Not Applicable when the ' +
                 'statement does not apply to your role.');

  CHARACTERISTICS.forEach(function (group, index) {
    form.addSectionHeaderItem()
      .setTitle(group.title)
      .setHelpText(group.help);

    group.items.forEach(function (text, i) {
      addRated(form, group.code + (i + 1) + '. ' + text);
    });

    // a page break after every characteristic except the last one
    if (index < CHARACTERISTICS.length - 1) {
      form.addPageBreakItem();
    }
  });

  form.addPageBreakItem()
    .setTitle('Part III. Overall Assessment and Findings');

  addChoice(form, '1. Overall rating of the system as a whole', [
    '5', '4', '3', '2', '1'
  ], true);

  var names = ['Functional Suitability', 'Performance Efficiency',
               'Compatibility', 'Reliability', 'Usability', 'Security',
               'Maintainability', 'Portability'];

  addChoice(form, '2. The strongest quality characteristic of the system',
            names, true);
  addChoice(form, '3. The quality characteristic that most needs ' +
                  'improvement', names, true);

  addParagraph(form,
    '4. Was there anything in the system that did not work, or that you ' +
    'could not find? Please describe it.', false);
  addParagraph(form,
    '5. What should be improved first? Please give the change you would ' +
    'like.', false);
  addParagraph(form, '6. Other comments or suggestions', false);
  addText(form,
    '7. Designation or role (optional; do not write your name if you ' +
    'prefer to remain anonymous)', false);

  Logger.log('Form created.');
  Logger.log('Edit link: ' + form.getEditUrl());
  Logger.log('Live link: ' + form.getPublishedUrl());
  Logger.log('Responses go to: ' + form.getDestinationId());
  return form.getPublishedUrl();
}

