# Moodle iContent 1.0.7
[![License](https://poser.pugx.org/covex-nn/moodle/license)](http://www.gnu.org/copyleft/gpl.html)

* Maintained by: [Léo Santos](https://br.linkedin.com/in/leorenis).
* Modified by: [AL Rachels] (https://drachels.org).

## About
The plugin for Moodle (Content pages), was designed so that from it, tutors, teachers and technicians can add content in courses, following usability and accessibility standards.

This tool will be responsible for signaling the good practice of adding text, images, multimedia, among others. In it, the content will be distributed in pages, so that the monitoring of the content does not become something exhausting and tiring. The plugin also has a bookmark, so that the participant can be located, based on last logged furthermore allow the inclusion of public or private notes, which the participant can provide feedback on the content studied on the page and enjoy and reply comments from other colleagues.

The plugin also allows the launch of questions about the content addressed, this provides better interaction between the participant and the virtual learning platform, and the feature may become an evaluation item or launch fractional notes on the items available in the course as defined in the plan of action. The described extension is fully responsive and can be accessed by any device.

## Sobre
O módulo de conteúdo interativo (Content pages) está sendo desenvolvido para que a partir dele, conteudistas, tutores, pedagogos e técnicos possam acrescentar conteúdo a um determinado curso, seguindo padrões de usabilidade e acessibilidade.
Essa ferramenta será responsável por promover boas práticas na inserção de conteúdos no formato texto, imagens, multimídias, entre outros.

O conteúdo será distribuído em paginas, garantindo uma leitura relevante, produtiva e eficaz. Oplugin também conta com um marcador de páginas, para que o participante possa se situar, baseando-se no seu último acesso. Além disso, permite a inserção de anotações públicas ou privadas em cada página, com isso o participante poderá comentar, curtir e responder comentarios de outros colegas ou até mesmo os seus.

O plugin também permite o lançamento de perguntas sobre o conteúdo abordado, isso proporciona melhor interação entre o participante e a plataforma virtual de aprendizagem, sendo que o recurso poderá se tornar um item avaliativo ou lançar notas fracionadas nos itens disponíveis no curso conforme definido no plano de ação. A extensão descrita é devidamente responsiva e poderá ser acessada por qualquer dispositivo.

## Compatibility / Compatibilidade
* PHP >= 8.0
* Moodle >= 4.0.10+ (Build: 20230806)

## Troubleshooting: Adding Questions in Content Page

If you see an error while adding a new question in Content Page, first verify
your Moodle and PHP versions meet the minimum compatibility requirements above.

Common symptom:

* `Exception - Class 'core_question\local\bank\question_bank_helper' not found`

What this usually means:

* The site is running an unsupported stack (for example, Moodle 4.0.1 and/or
	PHP 7.3), where required question-bank APIs are not available.

Recommended action:

* Upgrade to a supported environment:
	* PHP >= 8.0
	* Moodle >= 4.0.10+

## Question engine parity planning
See [QUESTION_ENGINE_PARITY_PLAN.md](QUESTION_ENGINE_PARITY_PLAN.md) for the concrete qtype-by-qtype parity checklist and migration sequence.

## Question points

For question-engine questions, a page maximum copied from the question-bank
default follows the default of the latest ready/draft question version. A
distinct activity-specific maximum remains an override.

Each submitted answer stores its maximum points. Reports, manual grading and
question-navigation thresholds use that recorded maximum, so later bank edits
do not rescale historical answers. The upgrade records the existing maximum for
older answers without changing their awarded points.

## Mobile support (4.0.0)

Phone browsers use the normal activity, with single-column content, constrained
images/media and wrapping touch-sized page-navigation and toolbar controls.
Wide authored tables remain horizontally scrollable within the content area.
Questions, notes, editing and submission actions continue to use the browser;
complex third-party question types or embeds may have their own device limits.

The official Moodle app supports **online native questions and page notes**,
alongside visible reference pages and authorized individual/group page sets.
Existing own page sets are linked from the reader; authorized public submissions
can also be viewed when explicitly selected. App rendering cleans executable
markup and does not reproduce page backgrounds or multi-column layouts.

**Answer the question(s)** uses the app's question components and Moodle's
Question Engine, not browser handoffs. Enabled built-in types include multiple
choice, true/false, short answer, numerical, matching, cloze, calculated variants,
drag-and-drop and text essays. Description questions are also displayed.
Question drafts persist on the server, can be saved explicitly, and autosave
every 30 seconds while the page is open. App page/page-set navigation and notes
actions save current question drafts first. Submission records the normal
iContent grades and historical maxima; essays await teacher grading. Existing
navigation gates and retry permissions remain enforced.

**Your notes and questions** supports both tabs, adding/editing text notes,
replies, likes/unlikes and confirmed deletion. Existing visibility, ownership,
private/featured/tutor-only permissions apply. Both sections respect the page's
initial expansion settings and can be expanded or collapsed inside the app.
The selected Notes/Questions tab controls both the displayed entries and where
new entries are saved. Successful saves refresh the list and tools in place,
clear the input and return to new-entry mode on the same tab. Failed saves keep
the input for retry. Use an entry's Edit tool to change a saved entry; save or
cancel an edit/reply before switching tabs.
Reading logs page/activity views, marks the page displayed and records view
completion without creating empty page-set submissions. Autosave does not create
extra view events. Native question drafts are included in privacy export/deletion
and removed when their activity is reset or deleted.

Unsupported third-party types, essay attachments or file-picker responses require
the explicit browser fallback for the whole question page; native submission never
records a partial grade for those pages. Page editing, presentation imports,
submitting page sets and teacher grading also remain browser operations.
Offline reading/interaction is not implemented. Automated server/controller tests
do not replace device testing: rendering, embedded media, calculated-simple and
image/marker drag-and-drop interactions still need actual Moodle-app verification.

Site administrators must enable Moodle mobile web services and permit site plugins
under the site's Moodle App settings; app-plan/custom-app restrictions may also
affect availability. After upgrading, refresh the activity in the app or log out
and back in if the new handler is not discovered. No additional iContent server
worker is needed for app interactions (presentation imports still require one).
Build 2026110703 fixes controller initialization and draft saving with the app's
already-decoded response data, restoring page navigation and notes/question saves.
Build 2026110704 fixes the shared Notes/Questions tab selection and clears the
form after successful saves without reloading the activity. Earlier versions
could save Questions entries under Notes when the separate selectors disagreed;
existing entries are not automatically moved between tabs.
Complete the Moodle plugin upgrade and refresh the activity in the app to load
the corrected controller.

## Assignment rubrics

When a Content Pages activity is a page-set assignment and has a grade, its settings
can use Moodle's built-in rubric (`gradingform_rubric`) only. Other grading-form plugins
are not offered. Define the rubric from Advanced grading in the activity menu. Teachers
use that rubric when grading a submitted page set. Students can see the criteria, and
the completed rubric after grading. Direct numeric grading remains available until the
rubric is ready. Question grades are unchanged.

Pages and Results / Grades show the page-rubric points, the combined page-score
subtotal and the current activity grade. Page-rubric points are scaled to the
page's configured maximum, not the rubric's raw criterion-point total.
Editing **Rubric page maximum mark** rescales saved page reviews to that maximum
while preserving their percentage, including pending reviews' possible points.
Authorized teachers viewing a student's page can see its completed page rubric.
Hidden gradebook grades and private page-set scores remain hidden from other learners.

For assignment activities, page-score subtotals include the learner's reference-page
scores and their own page set, never another learner's public page set. Group-owned
pages pool eligible group members' rubric and question scores; members without their
own answers also receive the shared group-page result. Reference-page scores remain
individual. Each learner's latest answer per question contributes its stored maximum;
unanswered questions and ungraded rubric reviews retain their possible points.
The subtotal is scaled to the activity's grade range. Pending-only work does not
create an awarded activity grade.

A saved whole-page-set grade remains the final override, including distinct member
grades set by the teacher. Later page grading does not replace it. Page subtotals stay
visible separately; Results / Grades use the actual gradebook grade, including
overrides. Locked or overridden gradebook entries are preserved. Moodle's grade-update
hook can refresh existing assignment page totals without changing saved page-set grades.

## License / Licença
* The iContent uses the GPL-3.0+ license, to learn more read the link: [GPL license](http://www.gnu.org/copyleft/gpl.html).
* O iContent utiliza a licença GPL-3.0+, para saber mais leia no link: [GPL license](http://www.gnu.org/copyleft/gpl.html).