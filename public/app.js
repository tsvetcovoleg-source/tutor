(() => {
  const recordBtn = document.getElementById('recordBtn');

  let mediaRecorder = null;
  let audioChunks = [];
  let selectedMimeType = '';
  let stream = null;

  const MAX_SECONDS = 300;
  let autoStopTimer = null;

  // Different hosting setups may expose API under different paths.
  const API_ENDPOINT_CANDIDATES = [
    '/api/upload_audio.php',
    '../api/upload_audio.php',
    'api/upload_audio.php',
  ];

  const TRANSCRIBE_ENDPOINT_CANDIDATES = [
    '/api/transcribe.php',
    '../api/transcribe.php',
    'api/transcribe.php',
  ];

    const log = (text) => {
    if (typeof window.appLog === 'function') {
      window.appLog(text);
    }
  };

  const pickMimeType = () => {
    const candidates = [
      'audio/webm;codecs=opus',
      'audio/webm',
      'audio/mp4',
      'audio/m4a',
      'audio/ogg'
    ];

    if (typeof MediaRecorder === 'undefined') {
      return '';
    }

    for (const type of candidates) {
      if (MediaRecorder.isTypeSupported(type)) {
        return type;
      }
    }

    return '';
  };

  const mimeTypeToExt = (mimeType) => {
    if (!mimeType) return 'webm';
    if (mimeType.includes('webm')) return 'webm';
    if (mimeType.includes('mp4')) return 'mp4';
    if (mimeType.includes('m4a')) return 'm4a';
    if (mimeType.includes('ogg')) return 'ogg';
    return 'webm';
  };

  const cleanupStream = () => {
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }
  };

  const parseResponseBody = async (response) => {
    const rawText = await response.text();

    if (!rawText) {
      return { rawText: '', json: null };
    }

    try {
      return { rawText, json: JSON.parse(rawText) };
    } catch (_) {
      return { rawText, json: null };
    }
  };

  const tryUploadToEndpoint = async (blob, ext, endpoint) => {
    const formData = new FormData();
    formData.append('audio', blob, `recording.${ext}`);

    const response = await fetch(endpoint, {
      method: 'POST',
      body: formData
    });

    const { rawText, json } = await parseResponseBody(response);

    if (response.ok && json && json.status === 'success') {
      return { endpoint, json };
    }

    // Try another endpoint if this one clearly does not exist.
    if (response.status === 404) {
      return null;
    }

    const serverMessage = json?.message || response.statusText || 'Upload failed';
    const stage = json?.stage ? ` (stage: ${json.stage})` : '';
    const debugMessage = json?.debug?.exception_message ? ` | ${json.debug.exception_message}` : '';
    const textPreview = !json && rawText ? ` | ${rawText.slice(0, 120)}` : '';

    throw new Error(`HTTP ${response.status}: ${serverMessage}${stage}${debugMessage}${textPreview}`);
  };

  const uploadAudio = async (blob, ext) => {
    log(`Uploading audio (${blob.size} bytes, .${ext})...`);

    let lastError = null;

    for (const endpoint of API_ENDPOINT_CANDIDATES) {
      try {
        log(`Trying upload endpoint: ${endpoint}`);
        const result = await tryUploadToEndpoint(blob, ext, endpoint);

        if (result) {
          log(`Upload completed via ${endpoint}.`);
          return result;
        }
      } catch (error) {
        lastError = error;
        log(`Upload failed via ${endpoint}: ${error.message}`);
        // Hard failure from server (not 404), stop trying fallback paths.
        break;
      }
    }

    if (lastError) {
      throw lastError;
    }

    throw new Error('Upload failed: API endpoint not found. Check server routing for /api/upload_audio.php');
  };


  const requestAutoTranscription = async (messageId) => {
    let lastError = null;

    for (const endpoint of TRANSCRIBE_ENDPOINT_CANDIDATES) {
      try {
        log(`Trying transcription endpoint: ${endpoint}`);
        const response = await fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: Number(messageId) })
        });

        const { rawText, json } = await parseResponseBody(response);

        if (response.ok && json && json.status === 'success') {
          log('Transcription completed successfully.');
          return;
        }

        if (response.status === 404) {
          continue;
        }

        const message = json?.message || response.statusText || 'Transcription failed';
        const preview = !json && rawText ? ` | ${rawText.slice(0, 120)}` : '';
        throw new Error(`${message}${preview}`);
      } catch (error) {
        lastError = error;
        break;
      }
    }

    if (lastError) {
      throw lastError;
    }

    throw new Error('Transcription endpoint not found (/api/transcribe.php).');
  };

  const setRecordButtonState = (isRecording) => {
    if (!recordBtn) return;
    if (isRecording) {
      recordBtn.dataset.state = 'recording';
      recordBtn.innerHTML = '<span class="icon">⏹️</span><span>Stop recording</span>';
    } else {
      recordBtn.dataset.state = 'idle';
      recordBtn.innerHTML = '<span class="icon">🎙️</span><span>Start recording</span>';
    }
  };

  const resetUI = () => {
    if (recordBtn) recordBtn.disabled = false;
    setRecordButtonState(false);
  };

  const startRecording = async () => {
    try {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        log('Your browser does not support microphone recording.');
        return;
      }

      log('Requesting microphone access...');

      stream = await navigator.mediaDevices.getUserMedia({
        audio: {
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true
        }
      });

      selectedMimeType = pickMimeType();
      log(`Using recording format: ${selectedMimeType || 'browser default'}.`);

      const options = selectedMimeType ? { mimeType: selectedMimeType } : undefined;
      mediaRecorder = new MediaRecorder(stream, options);
      audioChunks = [];

      mediaRecorder.addEventListener('dataavailable', (event) => {
        if (event.data && event.data.size > 0) {
          audioChunks.push(event.data);
        }
      });

      mediaRecorder.addEventListener('stop', async () => {
        try {
          const finalType = selectedMimeType || 'audio/webm';
          const ext = mimeTypeToExt(finalType);
          const blob = new Blob(audioChunks, { type: finalType });
          log('Recording stopped. Processing audio...');

          const uploadResult = await uploadAudio(blob, ext);
          const messageId = uploadResult?.json?.message_id;

          if (messageId) {
            log('Transcribing your answer...');
            await requestAutoTranscription(messageId);
            log('Done! Updating your history...');
            window.location.reload();
            return;
          }

          log('Transcription skipped: missing message id.');
        } catch (error) {
          console.error(error);
          log(`Something went wrong after stopping: ${error.message}`);
        } finally {
          cleanupStream();
          resetUI();
        }
      });

      mediaRecorder.start();
      if (recordBtn) recordBtn.disabled = false;
      setRecordButtonState(true);
      log('Recording started. Speak when you are ready.');

      clearTimeout(autoStopTimer);
      autoStopTimer = setTimeout(() => {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
          log(`Auto-stop reached after ${MAX_SECONDS} seconds.`);
          mediaRecorder.stop();
        }
      }, MAX_SECONDS * 1000);
    } catch (error) {
      console.error(error);
      cleanupStream();
      resetUI();
      log(`Could not start recording: ${error.message}`);
    }
  };

  const stopRecording = () => {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
      clearTimeout(autoStopTimer);
      log('Stopping recording...');
      mediaRecorder.stop();
    }
  };

  if (recordBtn) {
    recordBtn.addEventListener('click', () => {
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        stopRecording();
      } else {
        startRecording();
      }
    });
  }
})();
