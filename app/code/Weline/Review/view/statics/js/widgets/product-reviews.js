(function () {
  'use strict';

  function bootRoot(root, messages) {
    if (!root || root.dataset.reviewBound === '1') {
      return;
    }
    root.dataset.reviewBound = '1';

    var typeCode = root.dataset.typeCode || 'product';
    var entityUuid = root.dataset.entityUuid || '';
    var pageSize = Math.max(1, Math.min(50, Number(root.dataset.pageSize || 10) || 10));
    var fieldsRoot = root.querySelector('[data-review-fields]');
    var itemsRoot = root.querySelector('[data-review-items]');
    var previewRoot = root.querySelector('[data-review-preview]');
    var form = root.querySelector('[data-review-form]');
    var submit = root.querySelector('[data-review-submit]');
    var feedback = root.querySelector('[data-review-message]');
    var average = root.querySelector('[data-review-average]');
    var count = root.querySelector('[data-review-count]');
    if (!fieldsRoot || !itemsRoot || !previewRoot || !form || !submit || !feedback || !average || !count) {
      return;
    }

    var apiPromise = null;
    var schemaFields = [];

    function unwrap(result) {
      return result && result.data && typeof result.data === 'object' && result.success === undefined
        ? result.data
        : result;
    }

    function make(tag, className, text) {
      var node = document.createElement(tag);
      if (className) {
        node.className = className;
      }
      if (typeof text === 'string') {
        node.textContent = text;
      }
      return node;
    }

    function wait(ms) {
      return new Promise(function (resolve) {
        setTimeout(resolve, ms);
      });
    }

    async function resolveApi() {
      for (var attempt = 0; attempt < 60; attempt += 1) {
        if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
          return window.Weline.Api.resource('review');
        }
        if (window.Weline && typeof window.Weline.load === 'function') {
          try {
            await window.Weline.load('api');
          } catch (error) {
            /* retry */
          }
        }
        await wait(100);
      }
      throw new Error(messages.loadFailed);
    }

    function api() {
      return apiPromise || (apiPromise = resolveApi());
    }

    function fileToBase64(file) {
      return new Promise(function (resolve, reject) {
        var reader = new FileReader();
        reader.onload = function () {
          resolve(String(reader.result || '').split(',').pop() || '');
        };
        reader.onerror = function () {
          reject(new Error(messages.mediaInvalid));
        };
        reader.readAsDataURL(file);
      });
    }

    function paintRatingGroup(group, preview) {
      var inputs = Array.from(group.querySelectorAll('input[type=radio]'));
      var selected = Number((inputs.find(function (input) {
        return input.checked;
      }) || {}).value || 0);
      var active = preview === undefined ? selected : Number(preview || 0);
      group.querySelectorAll('.weline-review__rating-choice').forEach(function (choice, index) {
        var value = Number(choice.dataset.value || 0);
        choice.classList.toggle('is-filled', value <= active);
        choice.classList.toggle('is-preview', preview !== undefined && value <= active);
        choice.classList.toggle('is-current', value === selected);
        choice.setAttribute('aria-checked', value === selected ? 'true' : 'false');
        choice.tabIndex = (selected ? value === selected : index === 0) ? 0 : -1;
      });
    }

    function chooseRating(group, value, focus) {
      var input = group.querySelector('input[value="' + String(value) + '"]');
      if (!input) {
        return;
      }
      input.checked = true;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      paintRatingGroup(group);
      if (focus) {
        var choice = input.closest('.weline-review__rating-choice');
        if (choice) {
          choice.focus();
        }
      }
    }

    function fieldInput(field) {
      var wrap = make('div', 'weline-review__field');
      var id = 'review-' + field.key + '-' + entityUuid;
      var label = make('label', '', String(field.label || field.key));
      label.htmlFor = id;
      if (field.required) {
        label.appendChild(make('span', 'weline-review__required', ' *'));
      }

      if (field.type === 'checkbox') {
        var row = make('label', 'weline-review__checkbox');
        var checkbox = make('input');
        checkbox.type = 'checkbox';
        checkbox.id = id;
        checkbox.name = field.key;
        checkbox.checked = field.default === true;
        row.append(checkbox, document.createTextNode(String(field.label || field.key)));
        wrap.appendChild(row);
        return wrap;
      }

      if (field.type === 'image' || field.type === 'video') {
        var upload = make('label', 'weline-review__upload');
        upload.htmlFor = id;
        var icon = make('span', 'weline-review__upload-icon', field.type === 'image' ? 'IMG' : 'VID');
        var copy = make('span', 'weline-review__upload-copy');
        copy.append(
          make('strong', '', String(field.label || field.key)),
          make('small', '', field.type === 'image' ? messages.imageLimit : messages.videoLimit)
        );
        var action = make('span', 'weline-review__upload-action', String(field.label || field.key));
        var fileInput = make('input');
        fileInput.type = 'file';
        fileInput.id = id;
        fileInput.name = field.key;
        fileInput.accept = field.accept || '';
        fileInput.multiple = Number(field.max_files || 1) > 1;
        fileInput.dataset.kind = field.type;
        fileInput.dataset.maxFiles = String(field.max_files || 1);
        fileInput.dataset.maxSize = String(field.max_size || 0);
        fileInput.addEventListener('change', renderPreviews);
        upload.append(icon, copy, action, fileInput);
        wrap.appendChild(upload);
        return wrap;
      }

      wrap.appendChild(label);
      if (field.type === 'rating') {
        var group = make('div', 'weline-review__rating-stars');
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-label', String(field.label || field.key));
        var min = Math.max(1, Number(field.min || 1));
        var max = Math.max(min, Number(field.max || 5));
        for (var star = min; star <= max; star += 1) {
          (function (starValue) {
            var choice = make('label', 'weline-review__rating-choice');
            choice.dataset.value = String(starValue);
            choice.setAttribute('role', 'radio');
            choice.setAttribute('aria-label', String(field.label || field.key) + ' ' + starValue + '/' + max);
            choice.addEventListener('keydown', function (event) {
              var next = starValue;
              if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
                next = Math.min(max, starValue + 1);
              } else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
                next = Math.max(min, starValue - 1);
              } else if (event.key === 'Home') {
                next = min;
              } else if (event.key === 'End') {
                next = max;
              } else if (event.key !== 'Enter' && event.key !== ' ' && event.key !== 'Spacebar') {
                return;
              }
              event.preventDefault();
              chooseRating(group, next, true);
            });
            var radio = make('input');
            radio.type = 'radio';
            radio.id = id + '-' + starValue;
            radio.name = field.key;
            radio.value = String(starValue);
            radio.required = field.required === true;
            radio.tabIndex = -1;
            radio.setAttribute('aria-hidden', 'true');
            if (Number(field.default || 0) === starValue) {
              radio.checked = true;
            }
            radio.addEventListener('change', function () {
              paintRatingGroup(group);
            });
            choice.addEventListener('mouseenter', function () {
              paintRatingGroup(group, starValue);
            });
            choice.append(radio, document.createTextNode('★'));
            group.appendChild(choice);
          })(star);
        }
        group.addEventListener('mouseleave', function () {
          paintRatingGroup(group);
        });
        wrap.appendChild(group);
        paintRatingGroup(group);
        return wrap;
      }

      var input;
      if (field.type === 'textarea') {
        input = make('textarea');
        input.rows = 6;
      } else {
        input = make('input');
        input.type = field.type === 'email' ? 'email' : 'text';
      }
      input.id = id;
      input.name = field.key;
      if (field.required) {
        input.required = true;
      }
      if (field.min_length) {
        input.minLength = Number(field.min_length);
      }
      if (field.max_length) {
        input.maxLength = Number(field.max_length);
      }
      if (field.placeholder) {
        input.placeholder = String(field.placeholder);
      }
      if (field.default !== undefined) {
        input.value = String(field.default);
      }
      wrap.appendChild(input);
      return wrap;
    }

    function renderForm(data, options) {
      schemaFields = Array.isArray(data.fields) ? data.fields : [];
      fieldsRoot.replaceChildren.apply(fieldsRoot, schemaFields.map(fieldInput));
      submit.disabled = !(options && options.enableSubmit);
      if (options && options.previewNotice) {
        feedback.classList.remove('is-error');
        feedback.textContent = String(options.previewNotice);
      }
    }

    function embeddedPreviewSchema() {
      try {
        var parsed = JSON.parse(root.dataset.previewSchema || '[]');
        return Array.isArray(parsed) ? parsed : [];
      } catch (error) {
        return [];
      }
    }

    function renderPreviewShell() {
      var fields = embeddedPreviewSchema();
      if (!fields.length) {
        fieldsRoot.replaceChildren(make('p', 'weline-review__form-loading', messages.schemaFailed));
        itemsRoot.replaceChildren(make('p', 'weline-review__empty', messages.empty));
        count.textContent = messages.empty;
        return false;
      }
      renderForm({ fields: fields }, {
        enableSubmit: false,
        previewNotice: messages.previewOnly || messages.empty,
      });
      itemsRoot.replaceChildren(make('p', 'weline-review__empty', messages.empty));
      average.textContent = '—';
      count.textContent = messages.empty;
      return true;
    }

    function renderPreviews() {
      previewRoot.replaceChildren();
      form.querySelectorAll('input[type=file]').forEach(function (input) {
        Array.from(input.files || []).forEach(function (file) {
          var item = make('div', 'weline-review__preview-item');
          var kind = input.dataset.kind || 'image';
          var media = make(kind === 'video' ? 'video' : 'img');
          media.src = URL.createObjectURL(file);
          if (kind === 'video') {
            media.muted = true;
            media.controls = true;
            media.preload = 'metadata';
          } else {
            media.alt = file.name;
          }
          item.append(media, make('span', 'weline-review__preview-kind', kind === 'video' ? messages.video : messages.photo));
          previewRoot.appendChild(item);
        });
      });
    }

    function validateFiles() {
      var valid = true;
      form.querySelectorAll('input[type=file]').forEach(function (input) {
        var files = Array.from(input.files || []);
        var maxFiles = Number(input.dataset.maxFiles || 1);
        var maxSize = Number(input.dataset.maxSize || 0);
        if (files.length > maxFiles || files.some(function (file) {
          return maxSize > 0 && file.size > maxSize;
        })) {
          valid = false;
        }
      });
      return valid;
    }

    function renderReviews(data) {
      var reviews = Array.isArray(data.items) ? data.items : [];
      itemsRoot.replaceChildren();
      average.textContent = reviews.length ? Number(data.average_rating || 0).toFixed(1) + ' / 5' : '—';
      count.textContent = String(Number(data.total || 0)) + ' ' + String(messages.countSuffix);
      if (!reviews.length) {
        itemsRoot.appendChild(make('p', 'weline-review__empty', messages.empty));
        return;
      }
      reviews.forEach(function (review) {
        var article = make('article', 'weline-review__item');
        var head = make('div', 'weline-review__item-head');
        head.append(
          make('span', 'weline-review__stars', '★'.repeat(Math.max(1, Math.min(5, Number(review.rating || 5))))),
          make('span', 'weline-review__meta', String(review.reviewer || '') + (review.created_at ? ' · ' + String(review.created_at).slice(0, 10) : ''))
        );
        article.appendChild(head);
        if (review.title) {
          article.appendChild(make('h3', '', String(review.title)));
        }
        article.appendChild(make('p', 'weline-review__item-copy', String(review.content || '')));
        var extra = review.extra && typeof review.extra === 'object' ? review.extra : {};
        var ratingFields = schemaFields.filter(function (field) {
          return field.type === 'rating' && field.key !== 'rating' && Number(extra[field.key] || 0) > 0;
        });
        if (ratingFields.length) {
          var scores = make('div', 'weline-review__score-list');
          ratingFields.forEach(function (field) {
            var score = Math.max(1, Math.min(Number(field.max || 5), Number(extra[field.key] || 0)));
            var item = make('div', 'weline-review__score');
            item.append(
              make('span', 'weline-review__score-label', String(field.label || field.key)),
              make('span', 'weline-review__score-stars', '★'.repeat(score))
            );
            scores.appendChild(item);
          });
          article.appendChild(scores);
        }
        var mediaItems = Array.isArray(review.media) ? review.media : [];
        if (mediaItems.length) {
          var gallery = make('div', 'weline-review__media');
          mediaItems.forEach(function (item) {
            var figure = make('figure');
            var media = make(item.kind === 'video' ? 'video' : 'img');
            media.src = String(item.url || '');
            if (item.kind === 'video') {
              media.controls = true;
              media.preload = 'metadata';
            } else {
              media.loading = 'lazy';
              media.alt = String(item.name || messages.photo);
            }
            figure.append(media, make('span', 'weline-review__media-label', item.kind === 'video' ? 'VIDEO' : 'PHOTO'));
            gallery.appendChild(figure);
          });
          article.appendChild(gallery);
        }
        itemsRoot.appendChild(article);
      });
    }

    async function boot() {
      var formRendered = false;
      if (!entityUuid) {
        renderPreviewShell();
        return;
      }
      try {
        var client = await api();
        var results = await Promise.all([
          client.form({ type_code: typeCode, entity_uuid: entityUuid }),
          client.list({ type_code: typeCode, entity_uuid: entityUuid, page: 1, page_size: pageSize }),
        ]);
        var formData = unwrap(results[0]);
        var listData = unwrap(results[1]);
        if (!formData || formData.success === false) {
          throw new Error(formData && formData.message ? formData.message : messages.schemaFailed);
        }
        renderForm(formData, { enableSubmit: true });
        formRendered = true;
        if (!listData || listData.success === false) {
          throw new Error(listData && listData.message ? listData.message : messages.loadFailed);
        }
        renderReviews(listData);
      } catch (error) {
        if (!formRendered) {
          if (root.classList.contains('is-preview') && renderPreviewShell()) {
            return;
          }
          fieldsRoot.replaceChildren(make('p', 'weline-review__form-loading', error && error.message ? error.message : messages.schemaFailed));
        }
        if (!formRendered || !itemsRoot.querySelector('.weline-review__item')) {
          itemsRoot.replaceChildren(make('p', 'weline-review__empty', error && error.message ? error.message : messages.loadFailed));
          count.textContent = messages.loadFailed;
        }
        feedback.classList.add('is-error');
        feedback.textContent = error && error.message ? error.message : messages.loadFailed;
      }
    }

    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      feedback.classList.remove('is-error');
      var missingRating = schemaFields.find(function (field) {
        return field.type === 'rating' && field.required && !String((form.elements.namedItem(field.key) || {}).value || '');
      });
      if (missingRating) {
        feedback.classList.add('is-error');
        feedback.textContent = messages.ratingRequired;
        var focusTarget = form.querySelector('.weline-review__rating-stars [role=radio]');
        if (focusTarget) {
          focusTarget.focus();
        }
        return;
      }
      if (!form.reportValidity()) {
        return;
      }
      if (!validateFiles()) {
        feedback.classList.add('is-error');
        feedback.textContent = messages.mediaInvalid;
        return;
      }
      submit.disabled = true;
      feedback.textContent = messages.submitting;
      try {
        var client = await api();
        var mediaTokens = [];
        var fileInputs = Array.from(form.querySelectorAll('input[type=file]'));
        for (var i = 0; i < fileInputs.length; i += 1) {
          var input = fileInputs[i];
          var files = Array.from(input.files || []);
          for (var j = 0; j < files.length; j += 1) {
            var file = files[j];
            var uploadResult = unwrap(await client.upload({
              type_code: typeCode,
              entity_uuid: entityUuid,
              media_kind: input.dataset.kind || 'image',
              upload_base64: [{ name: file.name, type: file.type, data: await fileToBase64(file) }],
            }));
            if (!uploadResult || uploadResult.success === false) {
              throw new Error(uploadResult && uploadResult.message ? uploadResult.message : messages.submitFailed);
            }
            var token = uploadResult.media && uploadResult.media.token;
            if (token) {
              mediaTokens.push(String(token));
            }
          }
        }
        var values = {};
        schemaFields.forEach(function (field) {
          if (field.type === 'image' || field.type === 'video') {
            return;
          }
          var named = form.elements.namedItem(field.key);
          if (!named) {
            return;
          }
          values[field.key] = field.type === 'checkbox' ? Boolean(named.checked) : String(named.value || '');
        });
        var result = unwrap(await client.submit({
          type_code: typeCode,
          entity_uuid: entityUuid,
          values: values,
          media_tokens: mediaTokens,
        }));
        if (!result || result.success === false) {
          throw new Error(result && result.message ? result.message : messages.submitFailed);
        }
        feedback.textContent = String(result.message || '');
        form.reset();
        form.querySelectorAll('.weline-review__rating-stars').forEach(function (group) {
          paintRatingGroup(group);
        });
        previewRoot.replaceChildren();
      } catch (error) {
        feedback.classList.add('is-error');
        feedback.textContent = error && error.message ? error.message : messages.submitFailed;
      } finally {
        submit.disabled = false;
      }
    });

    boot();
  }

  function parseMessages(root) {
    try {
      return JSON.parse(root.getAttribute('data-review-messages') || '{}') || {};
    } catch (error) {
      return {};
    }
  }

  function initAll(scope) {
    (scope || document).querySelectorAll('[data-review-root]').forEach(function (root) {
      bootRoot(root, parseMessages(root));
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initAll(document);
    });
  } else {
    initAll(document);
  }

  window.WelineReviewProductWidget = {
    init: initAll,
  };
})();
