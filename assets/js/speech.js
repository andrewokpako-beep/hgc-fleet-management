window.fleetSpeak = (text, onState = () => {}) => {
    const synth = window.speechSynthesis;
    if (!synth || typeof SpeechSynthesisUtterance === 'undefined') {
        onState('Speech playback is not supported by this browser.');
        return false;
    }
    const value = String(text || '').trim();
    if (!value) {
        onState('There is no text to read aloud.');
        return false;
    }
    synth.cancel();
    const utterance = new SpeechSynthesisUtterance(value);
    utterance.lang = 'en-ZA';
    utterance.onstart = () => onState('Playing aloud…');
    utterance.onend = () => onState('Finished reading.');
    utterance.onerror = event => {
        if (event.error !== 'canceled' && event.error !== 'interrupted') {
            onState('Speech playback failed (' + event.error + '). Check that your device audio is on.');
        }
    };
    window.setTimeout(() => {
        try {
            synth.speak(utterance);
        } catch (error) {
            onState('Speech playback could not start. Check your browser audio settings.');
        }
    }, 100);
    return true;
};
