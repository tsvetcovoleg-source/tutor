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

  const uploadAudio = async (blob, ext) => {
    setStatus('Uploading...');

    const formData = new FormData();
    formData.append('audio', blob, `recording.${ext}`);

    const response = await fetch('/api/upload_audio.php', {
      method: 'POST',
      body: formData
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || data.status !== 'success') {
      const message = data.message || 'Upload failed';
      throw new Error(message);
    }

    setStatus('Done');
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
