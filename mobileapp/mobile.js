/* Native Moodle-app site-plugin controller. */
const component = this;
// The app decodes JSON otherdata; raw responses may still contain strings.
const decodeOtherdata = value => typeof value === 'string' ? JSON.parse(value) : value;
let destroyed = false;
component.nativeError = '';
component.nativeBusy = false;
component.questionAborted = false;
component.nativeArgs = decodeOtherdata(component.CONTENT_OTHERDATA.args);
component.nativeQuestions = component.CoreQuestionProvider.parseQuestions(decodeOtherdata(component.CONTENT_OTHERDATA.questions));
component.nativeNotes = decodeOtherdata(component.CONTENT_OTHERDATA.notes);
component.nativePastAnswers = decodeOtherdata(component.CONTENT_OTHERDATA.pastanswers);
component.questionsExpanded = component.CONTENT_OTHERDATA.questionsexpanded === '1';
component.notesExpanded = component.CONTENT_OTHERDATA.notesexpanded === '1';
component.editableQuestions = component.CONTENT_OTHERDATA.editablequestions === '1';
component.nativeUsageId = Number(component.CONTENT_OTHERDATA.usageid);
component.noteForm = {
    action: 'addnote', tab: 'note', noteid: 0, comment: '', private: false, featured: false, doubttutor: false,
};

component.executeNative = async function(work) {
    if (component.nativeBusy || destroyed) {
        return false;
    }
    component.nativeBusy = true;
    component.nativeError = '';
    try {
        await work();
        return true;
    } catch (error) {
        component.nativeError = error.message || String(error);
        return false;
    } finally {
        component.nativeBusy = false;
    }
};

component.requestNative = function(action, payload) {
    if (destroyed) {
        throw new Error(component.CONTENT_OTHERDATA.pageclosed);
    }
    return component.CoreSitePluginsProvider.getContent('mod_icontent', 'mobile_view', {
        ...component.nativeArgs, action: action, payload: JSON.stringify(payload),
    }, {getFromCache: false, saveToCache: false});
};

component.reloadNative = function(args) {
    if (destroyed) {
        throw new Error(component.CONTENT_OTHERDATA.pageclosed);
    }
    return component.updateContent(args, 'mod_icontent', 'mobile_view',
        undefined, {getFromCache: false, saveToCache: false});
};

component.collectNativeAnswers = async function() {
    const form = component.componentContainer.querySelector('form.icontent-native-questions');
    if (!form) {
        throw new Error('The question form is not available. Refresh this page.');
    }
    let fields = component.CoreQuestionHelperProvider.getAnswersFromForm(form);
    fields = await component.CoreQuestionHelperProvider.prepareAnswers(
        component.nativeQuestions, fields, false, 'mod_icontent', component.nativeArgs.cmid);
    const answers = {};
    Object.keys(fields).forEach(function(name) {
        answers[name] = typeof fields[name] === 'boolean' ? (fields[name] ? '1' : '0') : fields[name];
    });
    component.nativeQuestions.forEach(function(question) {
        answers[question.sequencefield] = String(question.sequencecheck);
    });
    return answers;
};

component.persistNativeDraft = async function() {
    if (!component.editableQuestions || component.questionAborted) {
        return;
    }
    const answers = await component.collectNativeAnswers();
    const response = await component.requestNative('save', {usageid: component.nativeUsageId, answers: answers});
    const questions = decodeOtherdata(response.otherdata.questions);
    questions.forEach(function(updated) {
        const current = component.nativeQuestions.find(function(question) {
            return question.slot === updated.slot;
        });
        if (current) {
            current.sequencecheck = updated.sequencecheck;
        }
    });
};

component.saveNativeDraft = function() {
    return component.executeNative(component.persistNativeDraft);
};

component.runNativeAction = function(action, payload) {
    return component.executeNative(async function() {
        if (action !== 'retry') {
            await component.persistNativeDraft();
        }
        const response = await component.requestNative(action, payload);
        if (action === 'retry') {
            await component.reloadNative(component.nativeArgs);
            return;
        }
        component.nativeNotes = decodeOtherdata(response.otherdata.notes);
        component.deleteConfirm = 0;
        if (['addnote', 'editnote', 'replynote'].includes(action) ||
                (action === 'deletenote' && component.noteForm.noteid &&
                    !component.nativeNotes.some(note => note.id === component.noteForm.noteid))) {
            component.cancelNativeNote();
        }
    });
};

component.submitNativeQuestions = function(usageid) {
    if (component.questionAborted) {
        return;
    }
    return component.executeNative(async function() {
        const answers = await component.collectNativeAnswers();
        await component.requestNative('submit', {usageid: usageid, answers: answers});
        await component.reloadNative(component.nativeArgs);
    });
};

component.navigateNativePage = function(args) {
    return component.executeNative(async function() {
        await component.persistNativeDraft();
        await component.reloadNative(args);
    });
};

component.startNativeNote = function(action, note) {
    component.noteForm = {
        action: action, tab: note.tab, noteid: note.id,
        comment: action === 'editnote' ? note.edittext : '', private: false, featured: false, doubttutor: false,
    };
};

component.cancelNativeNote = function() {
    component.noteForm = {
        action: 'addnote', tab: component.noteForm.tab, noteid: 0, comment: '',
        private: false, featured: false, doubttutor: false,
    };
};

component.saveNativeNote = function() {
    return component.runNativeAction(component.noteForm.action, {...component.noteForm});
};

const draftTimer = component.editableQuestions ? setInterval(component.saveNativeDraft, 30000) : undefined;
component.ngOnDestroy = function() {
    destroyed = true;
    clearInterval(draftTimer);
};
