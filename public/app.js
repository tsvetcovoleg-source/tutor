(() => {
  const startBtn = document.getElementById('startBtn');
  const stopBtn = document.getElementById('stopBtn');
  const statusEl = document.getElementById('status');

  let mediaRecorder = null;
  let audioChunks = [];
  let selectedMimeType = '';
  let stream = null;

  const MAX_SECONDS = 120;
  let autoStopTimer = null;

  // Different hosting setups may expose API under different paths.
  const API_ENDPOINT_CANDIDATES = [
    '/api/upload_audio.php',
    '../api/upload_audio.php',
    'api/upload_audio.php',
  ];

  const setStatus = (text) => {
    statusEl.textContent = text;
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

    const serverMessage = json?.message || 'Upload failed';
    const stage = json?.stage ? ` (stage: ${json.stage})` : '';
    const debugMessage = json?.debug?.exception_message ? ` | ${json.debug.exception_message}` : '';
    const textPreview = !json && rawText ? ` | ${rawText.slice(0, 120)}` : '';

    throw new Error(`HTTP ${response.status}: ${serverMessage}${stage}${debugMessage}${textPreview}`);
  };

  const uploadAudio = async (blob, ext) => {
    setStatus('Uploading...');

    let lastError = null;

    for (const endpoint of API_ENDPOINT_CANDIDATES) {
      try {
        const result = await tryUploadToEndpoint(blob, ext, endpoint);

        if (result) {
          setStatus('Done');
          return result;
        }
      } catch (error) {
        lastError = error;
        // Hard failure from server (not 404), stop trying fallback paths.
        break;
      }
    }

    if (lastError) {
      throw lastError;
    }

    throw new Error('Upload failed: API endpoint not found. Check server routing for /api/upload_audio.php');
  };

  const resetUI = () => {
    startBtn.disabled = false;
    stopBtn.disabled = true;
  };

  const startRecording = async () => {
    try {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setStatus('Microphone not supported on this browser');
        return;
      }

      setStatus('Requesting microphone permission...');

      stream = await navigator.mediaDevices.getUserMedia({
        audio: {
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true
        }
      });

      selectedMimeType = pickMimeType();

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

          await uploadAudio(blob, ext);
        } catch (error) {
          console.error(error);
          setStatus(`Error: ${error.message}`);
        } finally {
          cleanupStream();
          resetUI();
        }
      });

      mediaRecorder.start();
      startBtn.disabled = true;
      stopBtn.disabled = false;
      setStatus('Recording...');

      clearTimeout(autoStopTimer);
      autoStopTimer = setTimeout(() => {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
          mediaRecorder.stop();
        }
      }, MAX_SECONDS * 1000);
    } catch (error) {
      console.error(error);
      cleanupStream();
      resetUI();
      setStatus(`Error: ${error.message}`);
    }
  };

  const stopRecording = () => {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
      clearTimeout(autoStopTimer);
      mediaRecorder.stop();
    }
  };

  startBtn.addEventListener('click', startRecording);
  stopBtn.addEventListener('click', stopRecording);
})();
