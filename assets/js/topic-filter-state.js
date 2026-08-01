(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.topicFilterState = api;
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';
    function matches(queueState, showReady, showAction) {
        if (queueState === 'ready') return Boolean(showReady);
        if (queueState === 'action') return Boolean(showAction);
        return true;
    }
    function counts(queueStates) {
        return queueStates.reduce(function (result, state) {
            result[state === 'ready' || state === 'action' ? state : 'work'] += 1;
            return result;
        }, { work: 0, action: 0, ready: 0 });
    }
    return { matches: matches, counts: counts };
}));
