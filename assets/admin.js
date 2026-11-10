(function ($, acf, config) {
  'use strict';

  if (!acf || !config) {
    return;
  }

  function dismissNotice($notice) {
    var timer = $notice.data('dismissTimer');
    if (timer) {
      window.clearTimeout(timer);
    }

    $notice.removeClass('is-visible');
    window.setTimeout(function () {
      $notice.remove();
    }, 180);
  }

  function showNotice(type, message, editUrl) {
    $('.acf-global-blocks-notice').each(function () {
      dismissNotice($(this));
    });

    var isSuccess = type === 'success';
    var $notice = $('<div class="acf-global-blocks-notice" role="status" aria-live="polite"></div>')
      .addClass(isSuccess ? 'is-success' : 'is-error');
    var $icon = $('<span class="acf-global-blocks-notice-icon dashicons" aria-hidden="true"></span>')
      .addClass(isSuccess ? 'dashicons-yes-alt' : 'dashicons-warning');
    var $content = $('<div class="acf-global-blocks-notice-content"></div>');
    var $heading = $('<div class="acf-global-blocks-notice-heading"></div>')
      .text(isSuccess ? config.successTitle : config.errorTitle);
    var $message = $('<p></p>').text(message || config.noticeTitle);
    var $dismiss = $('<button type="button" class="acf-global-blocks-notice-dismiss"></button>')
      .attr('aria-label', config.dismiss)
      .append('<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>')
      .on('click', function () {
        dismissNotice($notice);
      });

    $content.append($heading, $message);

    if (editUrl) {
      $('<a class="button button-secondary"></a>')
        .attr('href', editUrl)
        .text(config.edit)
        .appendTo($content);
    }

    $notice.append($icon, $content, $dismiss).appendTo(document.body);
    window.requestAnimationFrame(function () {
      $notice.addClass('is-visible');
    });

    var timer = window.setTimeout(function () {
      dismissNotice($notice);
    }, isSuccess ? 8000 : 12000);
    $notice.data('dismissTimer', timer);
  }

  function getFieldLayouts($field) {
    return $field.find('.layout').filter(function () {
      return $(this).closest('.acf-field-flexible-content')[0] === $field[0] &&
        !$(this).hasClass('acf-clone');
    });
  }

  function getLayoutHandle($layout) {
    var $handle = $layout.find('> .acf-fc-layout-actions-wrap > .acf-fc-layout-handle');

    if (!$handle.length) {
      $handle = $layout.children('.acf-fc-layout-handle');
    }

    return $handle;
  }

  function getSuggestedTitle($layouts) {
    var labels = [];

    $layouts.each(function () {
      var text = $.trim(getLayoutHandle($(this)).clone().children().remove().end().text());
      if (text) {
        labels.push(text);
      }
    });

    return labels.join(' + ');
  }

  function addRowControls($field) {
    if (!$field.hasClass('acf-global-blocks-enabled-field')) {
      return;
    }

    getFieldLayouts($field).each(function () {
      var $layout = $(this);
      var $actionsWrap = $layout.children('.acf-fc-layout-actions-wrap');
      var $handle = getLayoutHandle($layout);
      var $controls = $actionsWrap.children('.acf-fc-layout-controls');
      var $existingButtons = $actionsWrap.children('.acf-global-blocks-save')
        .add($handle.children('.acf-global-blocks-save'));
      var $existingGroupLabels = $actionsWrap.children('.acf-global-blocks-group-select')
        .add($handle.children('.acf-global-blocks-group-select'));

      if ($layout.attr('data-layout') === config.layoutName) {
        return;
      }

      // Nested repeaters trigger ACF's append action for each new repeater row.
      // Keep one direct control pair per Flexible Content layout and remove any
      // duplicates created by earlier versions without touching nested layouts.
      if ($existingButtons.length) {
        $existingButtons.slice(1).remove();
        $existingGroupLabels.slice(1).remove();
        return;
      }

      var $groupLabel = $('<label class="acf-global-blocks-group-select"></label>')
        .on('click', function (event) {
          event.stopPropagation();
        });
      $('<input type="checkbox" class="acf-global-blocks-group-checkbox">')
        .appendTo($groupLabel);
      $('<span></span>').text(config.groupSelect).appendTo($groupLabel);

      var $button = $('<button type="button" class="button button-small acf-global-blocks-save"></button>')
        .text(config.button)
        .on('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          saveRows($layout, $(this), false);
        });

      if ($controls.length) {
        $groupLabel.insertBefore($controls);
        $button.insertBefore($controls);
      } else {
        $groupLabel.appendTo($handle);
        $button.appendTo($handle);
      }
    });
  }

  function addGroupButton($field) {
    if (!$field.hasClass('acf-global-blocks-enabled-field')) {
      return;
    }

    if ($field.find('.acf-global-blocks-save-group').length) {
      return;
    }

    var $actions = $field.children('.acf-input')
      .children('.acf-flexible-content')
      .children('.acf-actions');

    if (!$actions.length) {
      return;
    }

    $('<button type="button" class="button acf-global-blocks-save-group"></button>')
      .text(config.groupButton)
      .on('click', function (event) {
        event.preventDefault();

        var $selected = getFieldLayouts($field).filter(function () {
          return $(this).find('> .acf-fc-layout-actions-wrap .acf-global-blocks-group-checkbox').is(':checked');
        });

        if ($selected.length < 2) {
          showNotice('error', config.groupMinimum);
          return;
        }

        saveRows($selected, $(this), true);
      })
      .prependTo($actions);
  }

  function addControls($context) {
    var enabledSelector = '.acf-field-flexible-content.acf-global-blocks-enabled-field';
    var $fields = $context.find(enabledSelector)
      .addBack(enabledSelector)
      .add($context.closest(enabledSelector));

    $fields.each(function () {
      var $field = $(this);
      addRowControls($field);
      addGroupButton($field);
    });
  }

  function saveRows($layouts, $button, isGroup) {
    var $field = $layouts.first().closest('.acf-field-flexible-content');
    var field = acf.getField($field);
    var title = window.prompt(
      isGroup ? config.promptGroupTitle : config.promptTitle,
      getSuggestedTitle($layouts)
    );

    if (!title || !field) {
      return;
    }

    if (window.tinymce) {
      window.tinymce.triggerSave();
    }

    var payloads = $layouts.map(function () {
      return $(this).find(':input[name]:not(:disabled)').serialize();
    }).get();

    $button.prop('disabled', true).text(config.saving);

    $.post(config.ajaxUrl, {
      action: 'acf_global_blocks_save_row',
      nonce: config.nonce,
      title: title,
      field_key: field.get('key'),
      row_payloads: JSON.stringify(payloads)
    }).done(function (response) {
      if (!response || !response.success) {
        showNotice('error', response && response.data && response.data.message ? response.data.message : config.error);
        return;
      }

      var message = response.data.message || (isGroup ? config.groupSaved : config.saved);
      showNotice('success', message, response.data.editUrl || '');

      if (isGroup) {
        $layouts.find('.acf-global-blocks-group-checkbox').prop('checked', false);
      }
    }).fail(function (xhr) {
      var response = xhr.responseJSON;
      showNotice('error', response && response.data && response.data.message ? response.data.message : config.error);
    }).always(function () {
      $button.prop('disabled', false).text(isGroup ? config.groupButton : config.button);
    });
  }

  acf.addAction('ready', function () {
    addControls($(document));
  });

  acf.addAction('append', function ($el) {
    addControls($el && $el.length ? $el : $(document));
  });
})(jQuery, window.acf, window.ACFGlobalBlocks);
