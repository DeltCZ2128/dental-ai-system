/**
 * Build the IT Expert evaluation form in Google Forms.
 *
 * HOW TO RUN
 *   1. Open https://script.google.com and choose New project.
 *   2. Replace the contents of Code.gs with this whole file.
 *   3. Choose Run > createItExpertEvaluationForm and grant the permission
 *      that Google asks for. The permission is needed only to create the
 *      form in your own Drive.
 *   4. Open View > Logs. The log prints the edit link and the live link of
 *      the new form.
 *
 * The script creates one form with a section for the instructions, one
 * section for the respondent profile, one section for each of the eight
 * ISO/IEC 25010 characteristics, and a closing section. Every rated item
 * is a linear scale from 1 to 5, so the responses arrive as numbers and
 * can be scored with the weighted mean of Section 3.12 directly.
 *
 * Running it again creates a second form. It never edits a form that
 * already exists.
 */

var FORM_TITLE =
  'System Acceptability Evaluation Form - For IT Expert Respondents';

var SYSTEM_NAME =
  'AI-Based Intelligent Dental Appointment Scheduling System Using ' +
  'Machine Learning for Optimized Clinic Management';

var INSTRUCTIONS =
  'This questionnaire measures the acceptability of the system described ' +
  'below. You were selected as an IT expert respondent because of your ' +
  'background in software development, systems analysis, or system ' +
  'evaluation. Kindly access the system and perform at least one task in it ' +
  'before answering, so that every rating is based on direct observation. ' +
  'There is no right or wrong answer. Participation is voluntary and the ' +
  'form is answered without a name, so no answer can be traced back to you.\n' +
  '\n' +
  'SUMMARY OF THE SYSTEM: ' + SYSTEM_NAME + '\n' +
  'CLIENT: Abalos Dental Clinic\n' +
  'INSTRUMENT: Adapted from the ISO/IEC 25010 System and Software Quality ' +
  'Models';

var SCALE_HELP =
  'Five-Point Likert Scale\n' +
  '5 = 4.21 to 5.00  Highly Acceptable\n' +
  '4 = 3.41 to 4.20  Acceptable\n' +
  '3 = 2.61 to 3.40  Moderately Acceptable\n' +
  '2 = 1.81 to 2.60  Not Acceptable\n' +
  '1 = 1.00 to 1.80  Not Acceptable at All';

var LOW_LABEL = 'Not Acceptable at All';
var HIGH_LABEL = 'Highly Acceptable';

var CHARACTERISTICS = [
  {
    code: 'A',
    title: 'A. Functional Suitability',
    help: 'The degree to which the system provides functions that meet the ' +
          'stated requirements when used under the specified conditions.',
    items: [
      'The system provides the functions stated in the objectives of the ' +
        'study: appointment scheduling, patient records, dentist and ' +
        'schedule management, queue management, notifications, reports, ' +
        'and demand forecasting.',
      'The implemented functions agree with the processes documented in ' +
        'the context diagram, the data flow diagrams, and the activity ' +
        'diagrams of Chapter 3.',
      'The appointment validation correctly refuses a booking when the ' +
        'dentist is unavailable, when the slot is already taken, or when ' +
        'the requested date is not valid.',
      'The access of each respondent is limited to the functions of that ' +
        "respondent's role, namely administrator, dentist, or patient.",
      'Every function produces the output that the requirement specifies, ' +
        'including the refusal message when an appointment cannot be ' +
        'accepted.'
    ]
  },
  {
    code: 'B',
    title: 'B. Performance Efficiency',
    help: 'The degree to which the system performs its functions within ' +
          'the response times and resource limits expected in the clinic.',
    items: [
      'Pages load within an acceptable time during normal clinic use.',
      'The appointment validation returns its result without a delay that ' +
        'the respondent notices.',
      'The seven-day appointment demand forecast is generated without a ' +
        'delay that the respondent notices.',
      'The system continues to respond acceptably when several users use ' +
        'it at the same time.',
      'The database work of the system is efficient, in that routine ' +
        'actions do not repeat the same query unnecessarily or scan a ' +
        'whole table to answer a single record.'
    ]
  },
  {
    code: 'C',
    title: 'C. Compatibility',
    help: 'The degree to which the system operates correctly with the ' +
          'devices, browsers, and software in the environment described in ' +
          'Section 3.3.',
    items: [
      'The system runs on the PHP, MySQL, and web server environment ' +
        'described in Section 3.3 without requiring additional software.',
      'The interface displays correctly in the current versions of the ' +
        'common browsers, namely Chrome, Edge, and Firefox.',
      'The system works on both a desktop browser and a mobile browser ' +
        'without breaking its layout or its functions.',
      'The system uses standard web technologies, namely HTML5, CSS3, ' +
        'JavaScript, and Bootstrap, without components that conflict with ' +
        'one another.'
    ]
  },
  {
    code: 'D',
    title: 'D. Reliability',
    help: 'The degree to which the system performs its functions without ' +
          'failure and keeps the stored data correct during normal ' +
          'operation.',
    items: [
      'The system operates without unexpected errors or interruptions ' +
        'during continuous use.',
      'Records are neither lost nor duplicated when a form is submitted, ' +
        'including when the submit control is pressed more than once.',
      'Two requests for the same appointment slot are handled so that only ' +
        'one booking is accepted and the other is refused.',
      'Error conditions produce an understandable message instead of a ' +
        'system error page, a blank screen, or raw technical detail.',
      'The data a module writes is the same data another module reads, so ' +
        'that an update made by the dentist is seen at once by the patient.'
    ]
  },
  {
    code: 'E',
    title: 'E. Usability',
    help: 'The degree to which the system can be understood, learned, and ' +
          'operated by its users, including the clarity of what the system ' +
          'is doing at each step.',
    items: [
      'The navigation makes it clear at all times which module and which ' +
        'role the respondent is currently using.',
      'Forms identify their required fields, and invalid input is refused ' +
        'with a message that tells the respondent what to correct.',
      'Labels, layout, and terminology are consistent across the ' +
        'administrator, dentist, and patient interfaces.',
      'A respondent can complete a typical task, such as booking, ' +
        'confirming, joining the queue, or rescheduling, without referring ' +
        'to external documentation.'
    ]
  },
  {
    code: 'F',
    title: 'F. Security',
    help: 'The degree to which the system protects the data it holds and ' +
          'the accounts that use it, and the degree to which it resists ' +
          'requests that the system did not intend to receive.',
    items: [
      'Passwords are stored as one-way hashes, not as readable text and ' +
        'not through an obsolete digest such as MD5 or SHA1.',
      'The system prevents SQL injection by sending user input as bound ' +
        'parameters instead of joining the input into the SQL statement.',
      'The system encodes user-supplied values before displaying them, so ' +
        'that script injection (cross-site scripting) is prevented.',
      'A form that changes data carries an anti-forgery token, and a ' +
        'submission without a valid token is refused.',
      'The session identifier is renewed when a respondent signs in, and ' +
        'it is discarded when the respondent signs out.',
      'Error and failure messages do not disclose database names, file ' +
        'paths, credentials, or other internal detail to the respondent.'
    ]
  },
  {
    code: 'G',
    title: 'G. Maintainability',
    help: 'The degree to which the system can be corrected, updated, and ' +
          'extended without introducing defects elsewhere.',
    items: [
      'The source is organised into modules that correspond to the ' +
        'processes of Chapter 3, so that a change is made in one place ' +
        'rather than repeated in several.',
      'The code is readable through consistent naming, consistent ' +
        'indentation, and one clear responsibility per file or function.',
      'Configuration values, such as the database connection and the ' +
        'clinic settings, are separated from the application logic.',
      'Section headers and comments state the purpose of each handler and ' +
        'each function.',
      'The database is normalised, so that a record is stored once and ' +
        'referred to elsewhere rather than copied into several tables.'
    ]
  },
  {
    code: 'H',
    title: 'H. Portability',
    help: 'The degree to which the system can be moved to another ' +
          'environment without being rewritten.',
    items: [
      'The system can be deployed to another PHP and MySQL host by ' +
        'changing configuration only, without rewriting the source.',
      'The system requires no installation on the client device, because ' +
        'it is used entirely through a browser.',
      'The database can be recreated from the accompanying SQL file, so ' +
        'the system can be restored on a new host.',
      'The system works both on the XAMPP environment used by the ' +
        'researchers and on a comparable PHP hosting environment.'
    ]
  }
];

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/**
 * Add one linear scale item that runs from 1 to 5, so that the response
 * arrives as the number the weighted mean needs.
 */
function addScale(form, text) {
  var item = form.addScaleItem();
  item.setTitle(text);
  item.setBounds(1, 5);
  item.setLabels(LOW_LABEL, HIGH_LABEL);
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

/* ------------------------------------------------------------------ */
/* The form                                                            */
/* ------------------------------------------------------------------ */

function createItExpertEvaluationForm() {
  var form = FormApp.create(FORM_TITLE);

  // The instrument is answered without a name, so the email is not collected
  // and the respondent cannot be identified from the response record.
  form.setDescription(INSTRUCTIONS);
  form.setCollectEmail(false);
  form.setAllowResponseEdits(false);
  form.setShuffleQuestions(false);
  form.setLimitOneResponsePerUser(false);

  form.addSectionHeaderItem()
    .setTitle('Rating Scale')
    .setHelpText(SCALE_HELP);

  addChoice(form, '1. Affiliation of the respondent', [
    'College of Computer Studies Faculty',
    'Member of the Capstone Panel',
    'Licensed IT Practitioner',
    'Software Developer or Programmer',
    'Other'
  ], true);

  addText(form,
    '2. Number of years of experience in software development, systems ' +
    'analysis, or information technology', true);

  addChoice(form,
    '3. Have you accessed the system and performed at least one task in ' +
    'it? If No, kindly access the system first and then answer this form.',
    ['Yes', 'No'], true);

  var grid = form.addGridItem();
  grid.setTitle('4. Rate your familiarity with the technologies used by ' +
                'the system');
  grid.setRows([
    'PHP server-side programming',
    'MySQL or MariaDB database',
    'HTML5, CSS3, JavaScript, Bootstrap',
    'Statistical or machine-learning forecasting',
    'Web application security testing'
  ]);
  grid.setColumns(['Very Familiar', 'Familiar', 'Somewhat Familiar',
                   'Not Familiar']);
  grid.setRequired(true);

  var modules = form.addCheckboxItem();
  modules.setTitle('5. Which modules were you able to test? Check all ' +
                   'that apply.');
  modules.setChoiceValues([
    'Account registration and login',
    'Patient information management',
    'Dentist information and schedule management',
    'Appointment booking, validation, and conflict checking',
    'Queue management and QR walk-in check-in',
    'Notifications and appointment reminders',
    'Reports and dashboard monitoring',
    'Seven-day appointment demand forecast',
    'User account and clinic settings'
  ]);
  modules.setRequired(true);

  form.addPageBreakItem()
    .setTitle('Part II. Evaluation of the System')
    .setHelpText('The items are grouped according to the quality ' +
                 'characteristics of ISO/IEC 25010. The wording has been ' +
                 'adjusted for a technical respondent. Rate every item from ' +
                 '1 to 5, where 5 is Highly Acceptable and 1 is Not ' +
                 'Acceptable at All.');

  CHARACTERISTICS.forEach(function (group, index) {
    form.addSectionHeaderItem()
      .setTitle(group.title)
      .setHelpText(group.help);

    group.items.forEach(function (text, i) {
      addScale(form, group.code + (i + 1) + '. ' + text);
    });

    // a page break after every characteristic except the last one
    if (index < CHARACTERISTICS.length - 1) {
      form.addPageBreakItem();
    }
  });

  form.addPageBreakItem()
    .setTitle('Part III. Overall Assessment and Findings');

  addScale(form, '1. Overall rating of the system as a whole');
  addChoice(form, '2. The strongest quality characteristic of the system', [
    'Functional Suitability', 'Performance Efficiency', 'Compatibility',
    'Reliability', 'Usability', 'Security', 'Maintainability', 'Portability'
  ], true);
  addChoice(form,
    '3. The quality characteristic that most needs improvement', [
    'Functional Suitability', 'Performance Efficiency', 'Compatibility',
    'Reliability', 'Usability', 'Security', 'Maintainability', 'Portability'
  ], true);
  addParagraph(form,
    '4. Defects, errors, or unexpected behaviour observed. Please state ' +
    'the page and the steps you took.', false);
  addParagraph(form,
    '5. Recommended improvements, in order of priority', false);
  addParagraph(form, '6. Other comments or suggestions', false);
  addText(form,
    '7. Designation or affiliation (optional; do not write your name if ' +
    'you prefer to remain anonymous)', false);

  Logger.log('Form created.');
  Logger.log('Edit link: ' + form.getEditUrl());
  Logger.log('Live link: ' + form.getPublishedUrl());
  Logger.log('Responses go to: ' + form.getDestinationId());
  return form.getPublishedUrl();
}

