(function (root) {
  'use strict';
  // Laravel's TrimStrings middleware removes leading/trailing ASCII whitespace.
  // Textarea.value also normalizes CRLF to LF; compare canonical forms instead
  // of comparing the raw response body to the unsaved browser DOM value.
  function normalizeSource(value) {
    return String(value == null ? '' : value)
      .replace(/\r\n?/g, '\n')
      .replace(/^[ \t\n\v\0]+|[ \t\n\v\0]+$/g, '');
  }

  function hasUnsavedChanges(current, saved) {
    return normalizeSource(current) !== normalizeSource(saved);
  }

  function canExpand(state) {
    return !state.projectIsGenerating &&
      !state.saveInProgress &&
      state.serverCanExpand === true &&
      state.selectedKind === 'storyboard' &&
      state.savedKind === 'storyboard' &&
      normalizeSource(state.currentSource) !== '' &&
      !hasUnsavedChanges(state.currentSource, state.savedSource);
  }

  const api = {normalizeSource, hasUnsavedChanges, canExpand};
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root) root.AIDramaStoryboardState = api;
})(typeof window !== 'undefined' ? window : null);
