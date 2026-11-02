<!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Verifying you are not a robot</title>
        <style>
            body { font-family: sans-serif; text-align: center; padding: 50px 16px 0; background: #f9f9f9; color: #333; }
            button, input { font: inherit; }
            .language-switch { align-items: center; display: inline-flex; gap: .25rem; margin-bottom: 1rem; }
            .language-option { background: #fff; border: 1px solid #9ca3af; border-radius: 6px; color: #1f2937; cursor: pointer; font-weight: 700; min-height: 2.25rem; padding: 0 .75rem; }
            .language-option.is-active { background: #1f6feb; border-color: #1f6feb; color: #fff; }
            .challenge-panel { max-width: 28rem; margin: 1.5rem auto 0; }
            .challenge-prompt { background: #111827; border: 1px solid #374151; border-radius: 6px; box-shadow: 0 10px 22px rgba(17, 24, 39, .12); color: #f9fafb; font-size: 1.1rem; font-weight: 700; line-height: 1.35; margin-bottom: 1rem; padding: 1rem; }
            .challenge-button { min-height: 3rem; border: 0; border-radius: 6px; background: #1f6feb; color: white; cursor: pointer; font-weight: 700; padding: 0 1.25rem; }
            .challenge-phrase { font-size: 1.5rem; font-weight: 700; margin-bottom: .75rem; }
            .challenge-toolbar { display: flex; justify-content: center; margin: -.25rem 0 .75rem; }
            .challenge-reload-button { align-items: center; background: #fff; border: 1px solid #9ca3af; border-radius: 6px; color: #1f2937; cursor: pointer; display: inline-flex; height: 2.5rem; justify-content: center; padding: 0; width: 2.5rem; }
            .challenge-reload-button:hover { background: #f3f4f6; }
            .answer-zone { align-items: center; background: transparent; border-radius: 8px; box-sizing: border-box; display: flex; flex-wrap: wrap; justify-content: center; margin: 0 auto; padding: .45rem .35rem; }
            .answer-zone.is-attention { animation: answer-zone-shake 10s ease-in-out infinite, answer-zone-highlight 10s ease-out infinite; transform-origin: center; will-change: background-color, box-shadow, transform; }
            @keyframes answer-zone-shake {
                0%, 4.2%, 8.4%, 100% { transform: translate3d(0, 0, 0); }
                1% { transform: translate3d(-3px, 0, 0); }
                2.1% { transform: translate3d(3px, 0, 0); }
                3.1% { transform: translate3d(-2px, 0, 0); }
                3.8% { transform: translate3d(2px, 0, 0); }
                5.2% { transform: translate3d(-3px, 0, 0); }
                6.3% { transform: translate3d(3px, 0, 0); }
                7.3% { transform: translate3d(-2px, 0, 0); }
                8% { transform: translate3d(2px, 0, 0); }
            }
            @keyframes answer-zone-highlight {
                0% { background: rgba(250, 204, 21, .28); box-shadow: 0 0 0 4px rgba(250, 204, 21, .16); }
                12% { background: rgba(96, 165, 250, .16); box-shadow: 0 0 0 5px rgba(96, 165, 250, .1); }
                22%, 100% { background: transparent; box-shadow: 0 0 0 0 rgba(96, 165, 250, 0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .answer-zone.is-attention { animation: none; }
            }
            .choice-button { min-height: 3rem; border: 0; border-radius: 6px; background: #374151; color: white; cursor: pointer; font-weight: 700; margin: .25rem; padding: 0 1.25rem; }
            .color-choice { border: 2px solid white; box-shadow: 0 0 0 1px #9ca3af; min-width: 3.25rem; padding: 0; }
            .color-choice-blue { background: #2563eb; }
            .color-choice-green { background: #16a34a; }
            .color-choice-red { background: #dc2626; }
            .color-choice-yellow { background: #facc15; }
            .color-choice-purple { background: #9333ea; }
            .math-choice { min-width: 4.25rem; }
            .object-choice { min-height: 3.5rem; min-width: 3.5rem; border: 1px solid #9ca3af; border-radius: 6px; background: #fff; color: #111827; cursor: pointer; font-family: Arial, sans-serif; font-size: 1.5rem; font-weight: 700; margin: .25rem; padding: 0 .75rem; }
            .object-choice:hover { background: #f3f4f6; }
            .puzzle-board { display: grid; gap: .35rem; grid-template-columns: repeat(3, 1fr); margin: 0 auto .9rem; max-width: 13rem; width: min(100%, 13rem); }
            .puzzle-cell, .puzzle-piece { aspect-ratio: 1; border-radius: 6px; box-sizing: border-box; min-height: 3.6rem; position: relative; }
            .puzzle-cell { border: 1px solid #9ca3af; cursor: default; }
            .puzzle-cell.is-missing { background: #fff; border: 2px dashed #4b5563; cursor: pointer; }
            .puzzle-cell.is-missing.is-ready, .puzzle-cell.is-missing:hover { background: #eef2ff; }
            .puzzle-tray { display: flex; justify-content: center; }
            .puzzle-piece { border: 2px solid #111827; box-shadow: 0 5px 14px rgba(17, 24, 39, .2); cursor: grab; width: 3.9rem; }
            .puzzle-piece:active { cursor: grabbing; }
            .puzzle-piece.is-selected { outline: 3px solid #1f6feb; outline-offset: 3px; }
            .puzzle-tone-blue { background: #2563eb; }
            .puzzle-tone-green { background: #16a34a; }
            .puzzle-tone-red { background: #dc2626; }
            .puzzle-tone-yellow { background: #facc15; }
            .puzzle-tone-purple { background: #9333ea; }
            .puzzle-tone-teal { background: #0d9488; }
            .puzzle-tone-orange { background: #f97316; }
            .puzzle-tone-pink { background: #db2777; }
            .puzzle-tone-gray { background: #6b7280; }
            .reload-icon { fill: none; height: 1.25rem; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 2; width: 1.25rem; }
            .info { font-family: monospace; background: #eee; padding: 5px 10px; border-radius: 4px; display: inline-block; margin-top: 10px; }
            #verification-details { max-width: 38rem; margin: 1rem auto 0; line-height: 1.5; }
            .verification-failed { color: #1f2937; }
        </style>
        <script>
            (function() {
                var destination = "/v2/captcha?t=1785326804223";
                var maxRetries = 5;
                var autoChallengeDelayMs = 1500;
                var challenge = {"type":"puzzle","seed":544298,"prompt":"Place the missing piece.","promptBn":"হারানো টুকরাটি বসান।","slots":[{"id":"slot-1","tone":"blue"},{"id":"slot-2","tone":"green"},{"id":"slot-3","tone":"red"},{"id":"slot-4","tone":"yellow"},{"id":"slot-5","tone":"purple"},{"id":"slot-6","tone":"teal"},{"id":"slot-7","tone":"orange"},{"id":"slot-8","tone":"pink"},{"id":"slot-9","tone":"gray"}],"missing":{"id":"slot-6","tone":"teal"},"token":"eyJleHBpcmVzIjoxNzg1MzI3MTA0LCJub25jZSI6IjE3ODUzMjY4MDQ4MTU6MTYzNjI4IiwiYW5zd2VyUHJvb2YiOiJjNTk1MDJiZWNjMTg0MzMyNWE2YmJkMjRkNmMxNmQ4Y2FmY2M5MTM0NTFhNzU2NzVkNmY4N2FjN2E3ZDU1MDM5In0.058b6751c18f92743d4c7e7cf1c9b312d3409edb7b2aa0cd3955d8a858ff3fd1"};
                
                var retryWindowMs = 60000;
                var retryKey = 'bpanel.challenge.retries.' + destination;
                var now = Date.now();
                var retryState = { count: 0, lastAt: 0 };
                var statusElement;
                var currentLanguage = 'en';
                var currentStatusKey = 'preparing';
                var currentHeadingKey = 'heading';
                var translations = {
                    en: {
                        title: 'Verifying you are not a robot',
                        english: 'English',
                        bangla: 'বাংলা',
                        heading: 'Verifying you are not a robot...',
                        failedHeading: 'Browser verification failed',
                        preparing: 'Preparing verification...',
                        checkingBrowser: 'Checking browser verification...',
                        completePrompt: 'Complete the prompt below.',
                        completeRedirect: 'Verification complete. Redirecting...',
                        completeClose: 'Verification complete. You can close this window.',
                        loadingNew: 'Loading a new challenge...',
                        cookieFailure: 'Your browser could not store the verification cookie. Please enable cookies or use the latest version of Google Chrome, Firefox, or another modern browser.',
                        tooMany: 'Too many verification attempts. Verification failed.',
                        retryLimitDetails: 'The verification limit was reached. Please wait before trying again.',
                        defaultDetails: 'Please update your browser or switch to Google Chrome, Firefox, Microsoft Edge, or Safari. If you already use a modern browser, make sure cookies are enabled for this site and try again.',
                        defaultPrompt: 'Complete the verification.',
                        reloadChallenge: 'Load a new challenge',
                        missingPuzzleSpace: 'Missing puzzle space',
                        puzzlePiece: 'Puzzle piece',
                        missingPuzzlePiece: 'Missing puzzle piece',
                        ipAddress: 'Your IP address:'
                    },
                    bn: {
                        title: 'আপনি রোবট নন তা যাচাই করা হচ্ছে',
                        english: 'English',
                        bangla: 'বাংলা',
                        heading: 'আপনি রোবট নন তা যাচাই করা হচ্ছে...',
                        failedHeading: 'ব্রাউজার যাচাই ব্যর্থ হয়েছে',
                        preparing: 'যাচাই প্রস্তুত হচ্ছে...',
                        checkingBrowser: 'ব্রাউজার যাচাই করা হচ্ছে...',
                        completePrompt: 'নিচের নির্দেশনাটি সম্পন্ন করুন।',
                        completeRedirect: 'যাচাই সম্পন্ন হয়েছে। রিডাইরেক্ট করা হচ্ছে...',
                        completeClose: 'যাচাই সম্পন্ন হয়েছে। আপনি এই উইন্ডোটি বন্ধ করতে পারেন।',
                        loadingNew: 'নতুন চ্যালেঞ্জ লোড হচ্ছে...',
                        cookieFailure: 'আপনার ব্রাউজার যাচাই কুকি সংরক্ষণ করতে পারেনি। কুকি চালু করুন অথবা Google Chrome, Firefox বা অন্য আধুনিক ব্রাউজার ব্যবহার করুন।',
                        tooMany: 'অনেকবার যাচাইয়ের চেষ্টা করা হয়েছে। যাচাই ব্যর্থ হয়েছে।',
                        retryLimitDetails: 'যাচাইয়ের সীমা পূর্ণ হয়েছে। আবার চেষ্টা করার আগে কিছুক্ষণ অপেক্ষা করুন।',
                        defaultDetails: 'আপনার ব্রাউজার আপডেট করুন অথবা Google Chrome, Firefox, Microsoft Edge বা Safari ব্যবহার করুন। আধুনিক ব্রাউজার ব্যবহার করলে এই সাইটের জন্য কুকি চালু আছে কিনা নিশ্চিত করুন।',
                        defaultPrompt: 'যাচাই সম্পন্ন করুন।',
                        reloadChallenge: 'নতুন চ্যালেঞ্জ লোড করুন',
                        missingPuzzleSpace: 'হারানো পাজল ঘর',
                        puzzlePiece: 'পাজল টুকরা',
                        missingPuzzlePiece: 'হারানো পাজল টুকরা',
                        ipAddress: 'আপনার আইপি ঠিকানা:'
                    }
                };

                function readLanguage() {
                    try {
                        return window.localStorage.getItem('bpanel.challenge.language') === 'bn' ? 'bn' : 'en';
                    } catch (error) {
                        return 'en';
                    }
                }

                function text(key) {
                    return (translations[currentLanguage] && translations[currentLanguage][key]) || translations.en[key] || key;
                }

                function localized(enValue, bnValue) {
                    return currentLanguage === 'bn' && bnValue ? bnValue : enValue;
                }
                try {
                    retryState = JSON.parse(window.sessionStorage.getItem(retryKey) || 'null') || retryState;
                } catch (error) {
                    retryState = { count: 0, lastAt: 0 };
                }

                if (!retryState.lastAt || now - retryState.lastAt > retryWindowMs) {
                    retryState = { count: 0, lastAt: now };
                }

                function setStatus(message) {
                    statusElement = statusElement || document.getElementById('verification-status');
                    if (statusElement) {
                        statusElement.textContent = message;
                    }
                }

                function setStatusKey(key) {
                    currentStatusKey = key;
                    setStatus(text(key));
                }

                function setHeadingKey(key) {
                    currentHeadingKey = key;
                    var heading = document.getElementById('verification-heading');
                    if (heading) {
                        heading.textContent = text(key);
                    }
                }

                function applyLanguage() {
                    document.documentElement.lang = currentLanguage === 'bn' ? 'bn' : 'en';
                    document.title = text('title');
                    setHeadingKey(currentHeadingKey);
                    setStatusKey(currentStatusKey);

                    var details = document.getElementById('verification-details');
                    if (details && details.dataset.messageKey) {
                        details.textContent = text(details.dataset.messageKey);
                    }

                    var ipLabel = document.getElementById('ip-label');
                    if (ipLabel) {
                        ipLabel.textContent = text('ipAddress');
                    }

                    var englishButton = document.getElementById('language-en');
                    var banglaButton = document.getElementById('language-bn');
                    if (englishButton && banglaButton) {
                        englishButton.textContent = text('english');
                        banglaButton.textContent = text('bangla');
                        englishButton.setAttribute('aria-pressed', currentLanguage === 'en' ? 'true' : 'false');
                        banglaButton.setAttribute('aria-pressed', currentLanguage === 'bn' ? 'true' : 'false');
                        englishButton.className = currentLanguage === 'en' ? 'language-option is-active' : 'language-option';
                        banglaButton.className = currentLanguage === 'bn' ? 'language-option is-active' : 'language-option';
                    }
                }

                function setLanguage(language) {
                    currentLanguage = language === 'bn' ? 'bn' : 'en';
                    try {
                        window.localStorage.setItem('bpanel.challenge.language', currentLanguage);
                    } catch (error) {}
                    applyLanguage();

                    if (challenge.type !== 'auto' && document.body.className !== 'verification-failed') {
                        renderChallenge();
                    }
                }

                function setupLanguageControls() {
                    var englishButton = document.getElementById('language-en');
                    var banglaButton = document.getElementById('language-bn');
                    if (englishButton) {
                        englishButton.addEventListener('click', function() {
                            setLanguage('en');
                        });
                    }
                    if (banglaButton) {
                        banglaButton.addEventListener('click', function() {
                            setLanguage('bn');
                        });
                    }
                }

                function storeRetryState() {
                    try {
                        window.sessionStorage.setItem(retryKey, JSON.stringify(retryState));
                    } catch (error) {}
                }

                function verifySolved(answer) {
                    window.fetch('/_challenge-verify', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            token: challenge.token,
                            answer: String(answer)
                        })
                    }).then(function(response) {
                        if (!response.ok) {
                            requestNewChallenge();
                            return null;
                        }
                        return response.json();
                    }).then(function(result) {
                        if (!result) {
                            return;
                        }
                        if (!result.ok) {
                            requestNewChallenge();
                            return;
                        }
                        setStatusKey('completeRedirect');
                        setTimeout(function() {
                            if (false) {
                                window.close();
                                setStatusKey('completeClose');
                                return;
                            }
                            window.location.href = window.location.protocol + '//' + window.location.host + destination;
                        }, 500);
                    }).catch(function() {
                        requestNewChallenge();
                    });
                }

                function requestNewChallenge() {
                    setStatusKey('loadingNew');
                    window.setTimeout(function() {
                        var nextUrl = '/_challenge-engine?goto=' + encodeURIComponent(destination) + '&retry=' + Date.now();
                        if (false) {
                            nextUrl += '&close=1';
                        }
                        window.location.replace(nextUrl);
                    }, 350);
                }

                function addButton(container, label, className) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = className || 'challenge-button';
                    button.textContent = label;
                    container.appendChild(button);
                    return button;
                }

                function clearChallengeControl() {
                    var container = document.getElementById('challenge-control');
                    if (container) {
                        container.textContent = '';
                    }
                    return container;
                }

                function addChallengeReloadControl(container) {
                    var toolbar = document.createElement('div');
                    toolbar.className = 'challenge-toolbar';

                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'challenge-reload-button';
                    button.setAttribute('aria-label', text('reloadChallenge'));
                    button.title = text('reloadChallenge');
                    button.innerHTML = '<svg class="reload-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.34-5.66"/><path d="M20 4v6h-6"/></svg>';
                    button.addEventListener('click', function(event) {
                        if (event.isTrusted) {
                            requestNewChallenge();
                        }
                    });

                    toolbar.appendChild(button);
                    container.appendChild(toolbar);
                }

                function addAnswerZone(container) {
                    var zone = document.createElement('div');
                    zone.className = 'answer-zone is-attention';
                    container.appendChild(zone);
                    return zone;
                }

                function addPromptText(container) {
                    var prompt = document.createElement('div');
                    prompt.className = 'challenge-prompt';
                    prompt.textContent = localized(challenge.prompt, challenge.promptBn) || text('defaultPrompt');
                    container.appendChild(prompt);
                }

                function prepareManualChallenge() {
                    var container = document.getElementById('challenge-control');
                    if (!container) {
                        showFailure();
                        return null;
                    }
                    container.textContent = '';
                    addPromptText(container);
                    addChallengeReloadControl(container);
                    setStatusKey('completePrompt');
                    return addAnswerZone(container);
                }

                function renderChallenge() {
                    var answerZone = prepareManualChallenge();
                    if (!answerZone) {
                        return;
                    }
                    var board = document.createElement('div');
                    board.className = 'puzzle-board';
                    var tray = document.createElement('div');
                    tray.className = 'puzzle-tray';
                    var piece = document.createElement('button');
                    var pieceSelected = false;

                    function solvePuzzle(event) {
                        if (!event.isTrusted) {
                            return;
                        }
                        verifySolved('puzzle:' + challenge.missing.id);
                    }

                    function setPieceSelected(selected) {
                        pieceSelected = selected;
                        piece.className = selected ? 'puzzle-piece is-selected puzzle-tone-' + challenge.missing.tone : 'puzzle-piece puzzle-tone-' + challenge.missing.tone;
                    }

                    challenge.slots.forEach(function(slot) {
                        var cell = document.createElement('button');
                        var isMissing = slot.id === challenge.missing.id;
                        cell.type = 'button';
                        cell.className = isMissing ? 'puzzle-cell is-missing' : 'puzzle-cell is-filled puzzle-tone-' + slot.tone;
                        cell.setAttribute('aria-label', isMissing ? text('missingPuzzleSpace') : text('puzzlePiece'));

                        if (isMissing) {
                            cell.addEventListener('dragover', function(event) {
                                event.preventDefault();
                                cell.className = 'puzzle-cell is-missing is-ready';
                            });
                            cell.addEventListener('dragleave', function() {
                                cell.className = 'puzzle-cell is-missing';
                            });
                            cell.addEventListener('drop', function(event) {
                                event.preventDefault();
                                solvePuzzle(event);
                            });
                            cell.addEventListener('click', function(event) {
                                if (pieceSelected) {
                                    solvePuzzle(event);
                                }
                            });
                        }

                        board.appendChild(cell);
                    });

                    piece.type = 'button';
                    piece.draggable = true;
                    piece.setAttribute('aria-label', text('missingPuzzlePiece'));
                    piece.className = 'puzzle-piece puzzle-tone-' + challenge.missing.tone;
                    piece.addEventListener('dragstart', function(event) {
                        if (!event.isTrusted) {
                            event.preventDefault();
                            return;
                        }
                        event.dataTransfer.setData('text/plain', challenge.missing.id);
                        setPieceSelected(true);
                    });
                    piece.addEventListener('dragend', function() {
                        setPieceSelected(false);
                    });
                    piece.addEventListener('click', function(event) {
                        if (event.isTrusted) {
                            setPieceSelected(!pieceSelected);
                        }
                    });

                    tray.appendChild(piece);
                    answerZone.appendChild(board);
                    answerZone.appendChild(tray);
                }

                function showFailure() {
                    document.body.className = 'verification-failed';
                    clearChallengeControl();
                    setStatusKey('cookieFailure');
                    setHeadingKey('failedHeading');
                    var details = document.getElementById('verification-details');
                    if (details) {
                        details.hidden = false;
                        details.dataset.messageKey = 'defaultDetails';
                        details.textContent = text('defaultDetails');
                    }
                }

                function showRetryLimitReached() {
                    document.body.className = 'verification-failed';
                    setStatusKey('tooMany');
                    setHeadingKey('failedHeading');
                    clearChallengeControl();
                    var details = document.getElementById('verification-details');
                    if (details) {
                        details.hidden = false;
                        details.dataset.messageKey = 'retryLimitDetails';
                        details.textContent = text('retryLimitDetails');
                    }
                }

                function startVerification() {
                    currentLanguage = readLanguage();
                    setupLanguageControls();
                    applyLanguage();

                    if (retryState.count >= maxRetries) {
                        showRetryLimitReached();
                        return;
                    }

                    retryState.count += 1;
                    retryState.lastAt = now;
                    storeRetryState();
                    setStatusKey('preparing');

                    try {
                        renderChallenge();
                    } catch (error) {
                        showFailure();
                    }
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', startVerification);
                } else {
                    startVerification();
                }
            })();
        </script>
    </head>
    <body>
        <div class="language-switch" aria-label="Language">
            <button id="language-en" class="language-option is-active" type="button" aria-pressed="true">English</button>
            <button id="language-bn" class="language-option" type="button" aria-pressed="false">বাংলা</button>
        </div>
        <h2 id="verification-heading">Verifying you are not a robot...</h2>
        <p id="verification-status">Preparing verification...</p>
        <div id="challenge-control" class="challenge-panel"></div>
        <p id="verification-details" data-message-key="defaultDetails" hidden>Please update your browser or switch to Google Chrome, Firefox, Microsoft Edge, or Safari. If you already use a modern browser, make sure cookies are enabled for this site and try again.</p>
        <p><span id="ip-label">Your IP address:</span> <span class="info">103.122.249.93</span></p>
    </body>
    </html>