// Exercise production recipient selection with a minimal DOM fixture; no delivery transport.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const recipients = [7, 9, 12].map(id => ({value: String(id), checked: true, disabled: id === 9}));
const selectedMode = {checked: false};
let focused = false;
const nodes = {
    composeMailForm: {querySelector: () => selectedMode, querySelectorAll: () => recipients},
    composeMailUserList: {hidden: true, style: {}},
    composeMailFeedback: {textContent: ''},
    ghotiComposeMail: {scrollIntoView(){}},
    'composeMail-subject': {value: 'Existing draft', focus(){ focused = true; }}
};
const context = vm.createContext({
    window: {addEventListener(){}},
    document: {getElementById: id => nodes[id], addEventListener(){}},
    $: () => ({ready(){}, val: () => selectedMode.checked ? 'selected' : 'all'})
});
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '../ghoti.js'), 'utf8'), context);
context.composeMailToUser(7);
assert.deepEqual(recipients.map(item => item.checked), [true, false, false]);
assert.equal(selectedMode.checked, true);
assert.equal(nodes.composeMailUserList.hidden, false);
assert.equal(focused, true);
assert.equal(nodes['composeMail-subject'].value, 'Existing draft');
context.composeMailToUser(12);
assert.deepEqual(recipients.map(item => item.checked), [false, false, true]);
context.composeMailToUser(9);
assert.deepEqual(recipients.map(item => item.checked), [false, false, false]);
assert.match(nodes.composeMailFeedback.textContent, /no valid email address/);
context.composeMailToUser(7);
assert.equal(nodes.composeMailFeedback.textContent, '');
console.log('PASS: 9 user email selection assertions; no real mail');
