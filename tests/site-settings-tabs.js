// Production tab handlers with a DOM fixture; no settings writes.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const keys = ['identity', 'seo', 'visitors', 'admin', 'security', 'logging', 'mail', 'modules'];
const nodes = {};
const tabs = keys.map(key => {
    nodes['settings-panel-' + key] = {hidden: key !== 'identity', draft: ''};
    return nodes['settings-tab-' + key] = {
        id: 'settings-tab-' + key, attrs: {'aria-controls': 'settings-panel-' + key}, handlers: {},
        getAttribute(name){ return this.attrs[name]; },
        setAttribute(name, value){ this.attrs[name] = value; },
        addEventListener(name, handler){ this.handlers[name] = handler; },
        focus(){ this.focused = true; }
    };
});
const root = nodes.ghotiSiteSettings = {
    querySelectorAll(){ return tabs; },
    addEventListener(name, handler){ this[name] = handler; }
};
const context = vm.createContext({
    document: {getElementById: id => nodes[id], addEventListener(){}},
    window: {addEventListener(){}}, $: () => ({ready(){}})
});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../ghoti.js'), 'utf8'), context);
context.initSiteSettings();
function active(key){
    assert.deepEqual(keys.filter(item => !nodes['settings-panel-' + item].hidden), [key]);
    assert.deepEqual(tabs.filter(tab => tab.attrs['aria-selected'] === 'true').map(tab => tab.id), ['settings-tab-' + key]);
    assert.deepEqual(tabs.filter(tab => tab.tabIndex === 0).map(tab => tab.id), ['settings-tab-' + key]);
}
active('identity');
nodes['settings-panel-identity'].draft = 'Unsaved title';
tabs[4].handlers.click(); active('security');
context.initSiteSettings(); active('security');
function key(tab, key){ tab.handlers.keydown({key, preventDefault(){}}); }
key(tabs[4], 'ArrowRight'); active('logging');
assert.equal(tabs[5].focused, true);
key(tabs[5], 'End'); active('modules');
key(tabs[7], 'ArrowRight'); active('identity');
key(tabs[0], 'ArrowLeft'); active('modules');
key(tabs[7], 'Home'); active('identity');
assert.equal(nodes['settings-panel-identity'].draft, 'Unsaved title');
root.invalid({target: {closest(){ return {getAttribute(){ return 'settings-tab-security'; }}; }}});
active('security');
console.log('PASS: Settings tabs, keyboard navigation, retained selection and edits, hidden-field validation');
