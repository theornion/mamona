(function () {
  var form = document.getElementById("contactForm");

  if (!form) {
    return;
  }

  var status = document.getElementById("contactStatus");
  var submitButton = form.querySelector('input[type="submit"], button[type="submit"]');
  var defaultButtonText = submitButton ? submitButton.value || submitButton.textContent : "";
  var captchaContainer = document.getElementById("contactCaptcha");
  var captchaResponse = form.querySelector('input[name="cf-turnstile-response"]');
  var siteKey = form.getAttribute("data-turnstile-sitekey") || "";
  var widgetId = null;
  var turnstileLoader = null;
  var isSending = false;
  var captchaRequestActive = false;
  var captchaScriptUrl = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";

  function setStatus(message, type) {
    if (!status) {
      return;
    }

    status.textContent = message;
    status.classList.remove("is-success", "is-error");

    if (type) {
      status.classList.add(type);
    }
  }

  function setSubmitState(disabled, text) {
    if (!submitButton) {
      return;
    }

    submitButton.disabled = disabled;

    if ("value" in submitButton) {
      submitButton.value = text || defaultButtonText;
    } else {
      submitButton.textContent = text || defaultButtonText;
    }
  }

  function isCaptchaConfigured() {
    return siteKey && siteKey.indexOf("TU_WKLEJ") !== 0;
  }

  function clearCaptchaToken() {
    if (captchaResponse) {
      captchaResponse.value = "";
    }
  }

  function loadTurnstile() {
    if (window.turnstile) {
      return Promise.resolve(window.turnstile);
    }

    if (turnstileLoader) {
      return turnstileLoader;
    }

    turnstileLoader = new Promise(function (resolve, reject) {
      var script = document.createElement("script");

      script.src = captchaScriptUrl;
      script.async = true;
      script.defer = true;
      script.onload = function () {
        if (window.turnstile) {
          resolve(window.turnstile);
          return;
        }

        reject(new Error("Nie uda\u0142o si\u0119 uruchomi\u0107 weryfikacji captcha."));
      };
      script.onerror = function () {
        reject(new Error("Nie uda\u0142o si\u0119 pobra\u0107 weryfikacji captcha."));
      };

      document.head.appendChild(script);
    });

    return turnstileLoader;
  }

  function resetCaptcha(keepVisible) {
    clearCaptchaToken();

    if (window.turnstile && widgetId !== null) {
      window.turnstile.reset(widgetId);
    }

    if (captchaContainer && !keepVisible) {
      captchaContainer.hidden = true;
    }
  }

  function sendForm(turnstileUnavailable) {
    captchaRequestActive = false;
    isSending = true;
    setSubmitState(true, "Wysy\u0142anie...");
    setStatus("Wysy\u0142anie wiadomo\u015bci...", "");

    var formData = new FormData(form);

    if (turnstileUnavailable) {
      formData.set("turnstile-unavailable", "1");
    }

    fetch(form.action, {
      method: "POST",
      body: formData,
      headers: {
        Accept: "application/json"
      }
    })
      .then(function (response) {
        return response.json().catch(function () {
          throw new Error("Nie uda\u0142o si\u0119 odczyta\u0107 odpowiedzi serwera.");
        });
      })
      .then(function (data) {
        if (!data || data.ok !== true) {
          throw new Error((data && data.message) || "Nie uda\u0142o si\u0119 wys\u0142a\u0107 wiadomo\u015bci.");
        }

        form.reset();
        resetCaptcha(false);
        setStatus(data.message || "Dzi\u0119kujemy, wiadomo\u015b\u0107 zosta\u0142a wys\u0142ana.", "is-success");
      })
      .catch(function (error) {
        resetCaptcha(true);
        setStatus(error.message || "Wyst\u0105pi\u0142 b\u0142\u0105d podczas wysy\u0142ki wiadomo\u015bci.", "is-error");
      })
      .finally(function () {
        isSending = false;
        setSubmitState(false);
      });
  }

  function showCaptcha() {
    captchaRequestActive = true;

    if (!captchaContainer || !captchaResponse) {
      sendForm(true);
      return;
    }

    if (!isCaptchaConfigured()) {
      sendForm(true);
      return;
    }

    captchaContainer.hidden = false;
    setSubmitState(true, "Weryfikacja...");
    setStatus("Potwierd\u017a, \u017ce nie jeste\u015b automatem.", "");

    loadTurnstile()
      .then(function (turnstile) {
        if (widgetId !== null) {
          turnstile.reset(widgetId);
          setSubmitState(false);
          return;
        }

        try {
          widgetId = turnstile.render(captchaContainer, {
            sitekey: siteKey,
            callback: function (token) {
              if (!captchaRequestActive || isSending) {
                return;
              }

              captchaResponse.value = token;
              sendForm(false);
            },
            "expired-callback": function () {
              if (!captchaRequestActive) {
                return;
              }

              clearCaptchaToken();
              captchaRequestActive = false;
              setSubmitState(false);
              setStatus("Weryfikacja wygas\u0142a. Kliknij Wy\u015blij wiadomo\u015b\u0107 ponownie.", "is-error");
            },
            "error-callback": function () {
              if (!captchaRequestActive || isSending) {
                return;
              }

              clearCaptchaToken();
              sendForm(true);
            }
          });
        } catch (error) {
          sendForm(true);
        }
      })
      .catch(function () {
        sendForm(true);
      });
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();

    if (isSending) {
      return;
    }

    if (typeof form.reportValidity === "function" && !form.reportValidity()) {
      return;
    }

    if (captchaResponse && captchaResponse.value) {
      sendForm(false);
      return;
    }

    showCaptcha();
  });
}());
