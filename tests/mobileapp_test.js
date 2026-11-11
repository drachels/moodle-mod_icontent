const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../mobileapp/mobile.js'), 'utf8');

function createController({rawOtherdata = false, editableQuestions = true, responseNotes = []} = {}) {
    const requests = [];
    const reloads = [];
    const timers = [];
    const questions = [{slot: 1, sequencefield: 'q41:1_:sequencecheck', sequencecheck: 1}];
    const otherdata = value => rawOtherdata ? JSON.stringify(value) : value;
    const component = {
        CONTENT_OTHERDATA: {
            args: otherdata({cmid: 7, courseid: 8, pageid: 9, submissionid: 10}),
            questions: otherdata(editableQuestions ? questions : []),
            notes: otherdata([{id: 12, text: 'Existing note'}]), pastanswers: otherdata([]),
            questionsexpanded: '1', notesexpanded: '0', editablequestions: editableQuestions ? '1' : '0', usageid: '41',
            pageclosed: 'This page has been closed.',
        },
        CoreQuestionProvider: {parseQuestions: data => data},
        CoreQuestionHelperProvider: {
            getAnswersFromForm: () => ({choice0: true, choice1: false, answer: 'text'}),
            prepareAnswers: async (data, answers) => ({...answers, prepared: 'yes'}),
        },
        CoreSitePluginsProvider: {
            getContent: async (plugin, method, args, presets) => {
                requests.push({plugin, method, args, presets});
                return {otherdata: {
                    questions: otherdata([{...questions[0], sequencecheck: 2}]),
                    notes: otherdata(responseNotes),
                }};
            },
        },
        componentContainer: {querySelector: () => ({})},
        updateContent: (...args) => { reloads.push(args); },
    };
    const context = vm.createContext({
        component,
        setInterval: callback => {
            timers.push(callback);
            return 1;
        },
        clearInterval: id => timers.push(id),
    });
    vm.runInContext(`(function() {${source}\n}).call(component);`, context);
    return {component, requests, reloads, timers};
}

test('Controller initializes with the decoded otherdata supplied by the Moodle app', () => {
    const {component} = createController();
    assert.equal(component.nativeArgs.pageid, 9);
    assert.equal(component.nativeQuestions[0].slot, 1);
    assert.equal(component.nativeNotes[0].text, 'Existing note');
    assert.equal(component.nativePastAnswers.length, 0);
    assert.equal(typeof component.navigateNativePage, 'function');
    assert.equal(typeof component.saveNativeNote, 'function');
});

test('Raw JSON otherdata remains supported for initialization and draft responses', async () => {
    const {component, requests, reloads} = createController({rawOtherdata: true});
    assert.equal(component.nativeNotes[0].id, 12);
    assert.equal(await component.navigateNativePage({cmid: 7, courseid: 8, pageid: 11, submissionid: 10}), true);
    assert.equal(requests[0].args.action, 'save');
    assert.equal(component.nativeQuestions[0].sequencecheck, 2);
    assert.equal(reloads[0][0].pageid, 11);
});

test('Pages without editable questions can save notes and navigate without saving a question draft', async () => {
    const {component, requests, reloads, timers} = createController({editableQuestions: false});
    component.noteForm.comment = 'A note without questions';
    assert.equal(await component.saveNativeNote(), true);
    assert.deepEqual(requests.map(request => request.args.action), ['addnote']);
    assert.equal(JSON.parse(requests[0].args.payload).comment, 'A note without questions');
    assert.equal(await component.navigateNativePage({cmid: 7, courseid: 8, pageid: 11, submissionid: 10}), true);
    assert.equal(reloads[0][0].pageid, 11);
    assert.equal(timers.length, 0);
});

test('Native questions use app preparation, normalize choices and bypass the WS cache', async () => {
    const {component, requests, reloads} = createController();
    assert.equal(await component.submitNativeQuestions(41), true);
    const request = requests[0];
    assert.equal(request.plugin, 'mod_icontent');
    assert.equal(request.method, 'mobile_view');
    assert.equal(request.args.action, 'submit');
    assert.equal(request.args.submissionid, 10);
    assert.equal(request.presets.getFromCache, false);
    assert.equal(request.presets.saveToCache, false);
    const payload = JSON.parse(request.args.payload);
    assert.equal(payload.usageid, 41);
    assert.equal(payload.answers.choice0, '1');
    assert.equal(payload.answers.choice1, '0');
    assert.equal(payload.answers.prepared, 'yes');
    assert.equal(payload.answers['q41:1_:sequencecheck'], '1');
    assert.equal(reloads.length, 1);
    assert.equal(reloads[0][4].getFromCache, false);
});

test('Draft save updates sequence checks without discarding the rendered form', async () => {
    const {component, requests, reloads} = createController();
    assert.equal(await component.saveNativeDraft(), true);
    assert.equal(requests[0].args.action, 'save');
    assert.equal(component.nativeQuestions[0].sequencecheck, 2);
    assert.equal(reloads.length, 0);
    await component.submitNativeQuestions(41);
    assert.equal(JSON.parse(requests[1].args.payload).answers['q41:1_:sequencecheck'], '2');
});

test('Notes actions and page navigation save the question draft first', async () => {
    const {component, requests, reloads} = createController();
    component.noteForm.comment = 'A note';
    assert.equal(await component.saveNativeNote(), true);
    assert.deepEqual(requests.map(request => request.args.action), ['save', 'addnote']);
    assert.equal(JSON.parse(requests[1].args.payload).comment, 'A note');
    assert.equal(await component.navigateNativePage({cmid: 7, pageid: 11}), true);
    assert.equal(requests[2].args.action, 'save');
    assert.equal(reloads[0][0].pageid, 11);
});

for (const tab of ['note', 'doubt']) {
    for (const rawOtherdata of [false, true]) {
        test(`Saving a ${tab} with ${rawOtherdata ? 'raw' : 'decoded'} data clears the form and refreshes its list`, async () => {
            const saved = {id: 14, tab, text: 'Saved entry', edittext: 'Saved entry', canedit: true, canreply: true};
            const {component, requests, reloads} = createController({
                editableQuestions: false, rawOtherdata, responseNotes: [saved],
            });
            Object.assign(component.noteForm, {tab, comment: 'Saved entry', private: true, featured: true, doubttutor: true});
            component.notesExpanded = true;
            assert.equal(await component.saveNativeNote(), true);
            const payload = JSON.parse(requests[0].args.payload);
            assert.equal(payload.tab, tab);
            assert.equal(payload.comment, 'Saved entry');
            assert.equal(component.noteForm.tab, tab);
            assert.equal(component.noteForm.comment, '');
            assert.equal(component.noteForm.action, 'addnote');
            assert.equal(component.noteForm.noteid, 0);
            assert.equal(component.noteForm.private, false);
            assert.equal(component.noteForm.featured, false);
            assert.equal(component.noteForm.doubttutor, false);
            assert.equal(component.notesExpanded, true);
            assert.deepEqual(JSON.parse(JSON.stringify(component.nativeNotes)), [saved]);
            assert.equal(reloads.length, 0);
        });
    }

    for (const action of ['editnote', 'replynote']) {
        test(`${action} on a ${tab} uses its tab and returns to a blank new-entry form after saving`, async () => {
            const saved = {id: 14, tab, text: 'Updated entry', edittext: 'Updated entry', canedit: true, canreply: true};
            const {component, requests, reloads} = createController({editableQuestions: false, responseNotes: [saved]});
            component.startNativeNote(action, {id: 14, tab, edittext: 'Existing entry'});
            assert.equal(component.noteForm.tab, tab);
            assert.equal(component.noteForm.comment, action === 'editnote' ? 'Existing entry' : '');
            component.noteForm.comment = 'Updated entry';
            assert.equal(await component.saveNativeNote(), true);
            assert.equal(requests[0].args.action, action);
            const payload = JSON.parse(requests[0].args.payload);
            assert.equal(payload.noteid, 14);
            assert.equal(payload.tab, tab);
            assert.equal(component.noteForm.action, 'addnote');
            assert.equal(component.noteForm.noteid, 0);
            assert.equal(component.noteForm.comment, '');
            assert.equal(component.noteForm.tab, tab);
            assert.equal(component.nativeNotes[0].text, 'Updated entry');
            assert.equal(reloads.length, 0);
        });
    }
}

test('The same tab selection controls the notes list and new-entry form', () => {
    const template = fs.readFileSync(path.join(__dirname, '../templates/mobileapp/reader.mustache'), 'utf8');
    assert.match(template, /<ion-segment\b[^>]*\[\(ngModel\)\]="noteForm\.tab"/);
    assert.match(template, /\[hidden\]="note\.tab !== noteForm\.tab"/);
    assert.doesNotMatch(template, /notesTab|<ion-select\b/);
});

test('Cancelling an edit keeps the Questions tab selected and clears the input', () => {
    const {component} = createController();
    component.startNativeNote('editnote', {id: 14, tab: 'doubt', edittext: 'Existing question'});
    component.cancelNativeNote();
    assert.equal(component.noteForm.tab, 'doubt');
    assert.equal(component.noteForm.comment, '');
    assert.equal(component.noteForm.action, 'addnote');
});

test('Likes and deletion refresh the Questions list without losing an unsaved entry', async () => {
    const updated = {id: 14, tab: 'doubt', text: 'Question', likes: 1, liked: true, canedit: true, canreply: true};
    const {component, requests, reloads} = createController({editableQuestions: false, responseNotes: [updated]});
    component.noteForm.tab = 'doubt';
    component.noteForm.comment = 'Unsaved question';
    component.deleteConfirm = 14;
    assert.equal(await component.runNativeAction('likenote', {noteid: 14}), true);
    assert.equal(component.nativeNotes[0].liked, true);
    assert.equal(component.noteForm.comment, 'Unsaved question');
    assert.equal(component.noteForm.tab, 'doubt');
    assert.equal(await component.runNativeAction('deletenote', {noteid: 15}), true);
    assert.equal(component.deleteConfirm, 0);
    assert.equal(component.noteForm.comment, 'Unsaved question');
    assert.deepEqual(requests.map(request => request.args.action), ['likenote', 'deletenote']);
    assert.equal(reloads.length, 0);
});

test('Deleting a question being edited returns to a blank form on the same tab', async () => {
    const {component, reloads} = createController({editableQuestions: false, responseNotes: []});
    component.startNativeNote('editnote', {id: 14, tab: 'doubt', edittext: 'Question to delete'});
    assert.equal(await component.runNativeAction('deletenote', {noteid: 14}), true);
    assert.equal(component.noteForm.action, 'addnote');
    assert.equal(component.noteForm.comment, '');
    assert.equal(component.noteForm.tab, 'doubt');
    assert.equal(component.nativeNotes.length, 0);
    assert.equal(reloads.length, 0);
});

test('Failed Questions saves retain the entry, selected tab and displayed list for retry', async () => {
    const {component, reloads} = createController({editableQuestions: false});
    const notes = component.nativeNotes;
    component.startNativeNote('editnote', {id: 14, tab: 'doubt', edittext: 'Keep this question'});
    component.CoreSitePluginsProvider.getContent = async () => {
        throw new Error('Connection lost');
    };
    assert.equal(await component.saveNativeNote(), false);
    assert.equal(component.nativeError, 'Connection lost');
    assert.equal(component.noteForm.comment, 'Keep this question');
    assert.equal(component.noteForm.tab, 'doubt');
    assert.equal(component.noteForm.action, 'editnote');
    assert.equal(component.nativeNotes, notes);
    assert.equal(reloads.length, 0);
});

test('A pending note save cannot create duplicate requests', async () => {
    const {component} = createController({editableQuestions: false});
    let finishSave;
    let calls = 0;
    component.noteForm.tab = 'doubt';
    component.noteForm.comment = 'One question';
    component.CoreSitePluginsProvider.getContent = () => {
        calls++;
        return new Promise(resolve => { finishSave = resolve; });
    };
    const saving = component.saveNativeNote();
    assert.equal(await component.saveNativeNote(), false);
    assert.equal(calls, 1);
    assert.equal(component.noteForm.comment, 'One question');
    finishSave({otherdata: {notes: [{id: 14, tab: 'doubt', text: 'One question'}]}});
    assert.equal(await saving, true);
    assert.equal(component.noteForm.comment, '');
    assert.equal(component.nativeNotes.length, 1);
});

test('Errors are visible, leave form data intact, and do not navigate or submit twice', async () => {
    const {component, requests, reloads} = createController();
    component.noteForm.comment = 'Keep this note';
    component.CoreSitePluginsProvider.getContent = async () => {
        throw new Error('Connection lost');
    };
    assert.equal(await component.saveNativeNote(), false);
    assert.equal(component.nativeError, 'Connection lost');
    assert.equal(component.nativeBusy, false);
    assert.equal(component.noteForm.comment, 'Keep this note');
    assert.equal(reloads.length, 0);
    component.nativeBusy = true;
    assert.equal(await component.submitNativeQuestions(41), false);
    assert.equal(requests.length, 0);
});

test('Retry discards the draft only through its explicit server action; timers are cleaned up', async () => {
    const {component, requests, timers} = createController();
    await component.runNativeAction('retry', {});
    assert.deepEqual(requests.map(request => request.args.action), ['retry']);
    assert.equal(timers.length, 1);
    component.ngOnDestroy();
    assert.equal(timers[1], 1);
    assert.equal(await component.saveNativeDraft(), false);
    assert.equal(requests.length, 1);
});

test('Closing the page during response preparation prevents a delayed submission', async () => {
    const {component, requests, reloads} = createController();
    let finishPreparation;
    component.CoreQuestionHelperProvider.prepareAnswers = () => new Promise(resolve => {
        finishPreparation = resolve;
    });
    const submission = component.submitNativeQuestions(41);
    component.ngOnDestroy();
    finishPreparation({});
    assert.equal(await submission, false);
    assert.equal(requests.length, 0);
    assert.equal(reloads.length, 0);
});
