(function ($) {
    'use strict';

    function resetErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $form.find('.js-generated-error').remove();
    }

    function renderErrors($form, errors) {
        $.each(errors || {}, function (field, messages) {
            var inputName = field.replace(/\.([^.]+)/g, '[$1]');
            var $field = $form.find('[name="' + inputName + '"]');
            var $error = $form.find('[data-error-for="' + field + '"]');

            $field.addClass('is-invalid');
            if ($error.length) {
                $error.text(messages[0]);
            } else if ($field.length) {
                $('<div class="invalid-feedback js-generated-error"></div>').text(messages[0]).insertAfter($field.first());
            }
        });

        $form.find('.is-invalid').first().trigger('focus');
    }

    function showResult(response, fallbackMessage) {
        var success = response.status !== false;

        return Swal.fire({
            icon: success ? 'success' : 'error',
            text: response.msg || response.message || fallbackMessage
        });
    }

    window.erpAjaxForm = function (options) {
        var settings = $.extend({
            form: null,
            url: null,
            method: null,
            reload: false,
            redirect: false,
            alert: true
        }, options);

        if (!settings.form) {
            return;
        }

        $(document).off('submit.erpAjaxForm', settings.form).on('submit.erpAjaxForm', settings.form, function (event) {
            event.preventDefault();

            var $form = $(this);
            var $buttons = $form.find(':submit');
            var multipart = $form.attr('enctype') === 'multipart/form-data';

            if ($form.data('submitting')) {
                return;
            }

            $form.data('submitting', true);
            resetErrors($form);

            $buttons.each(function () {
                var $button = $(this);
                $button.data('original-html', $button.html());
                $button.prop('disabled', true).text($button.data('busy-text') || 'กำลังบันทึก...');
            });

            $.ajax({
                url: settings.url || $form.attr('action'),
                method: settings.method || $form.attr('method') || 'POST',
                data: multipart ? new FormData($form[0]) : $form.serialize(),
                processData: !multipart,
                contentType: multipart ? false : 'application/x-www-form-urlencoded; charset=UTF-8',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || ''
                }
            }).done(function (response) {
                var afterAlert = function () {
                    if (response.status === false) {
                        return;
                    }

                    if (settings.redirect && response.redirect) {
                        window.location.assign(response.redirect);
                    } else if (settings.reload === true) {
                        window.location.reload();
                    } else if (typeof settings.reload === 'string') {
                        var $table = $(settings.reload);
                        if ($table.length && $.fn.DataTable.isDataTable($table[0])) {
                            $table.DataTable().ajax.reload(null, false);
                        }
                    }
                };

                if (settings.alert || response.status === false) {
                    showResult(response, 'บันทึกข้อมูลเรียบร้อย').then(afterAlert);
                } else {
                    afterAlert();
                }
            }).fail(function (xhr) {
                var response = xhr.responseJSON || {};

                if (xhr.status === 422) {
                    renderErrors($form, response.errors);
                }

                Swal.fire({
                    icon: 'error',
                    text: response.msg || response.message || 'ไม่สามารถดำเนินการได้ กรุณาลองใหม่'
                });
            }).always(function () {
                $form.data('submitting', false);
                $buttons.each(function () {
                    var $button = $(this);
                    $button.prop('disabled', false).html($button.data('original-html'));
                });
            });
        });
    };

    window.erpAjaxDelete = function (options) {
        var settings = $.extend({
            button: null,
            url: null,
            method: 'DELETE',
            reload: false,
            redirect: false,
            confirm: 'ยืนยันการลบข้อมูลนี้หรือไม่?'
        }, options);

        if (!settings.button) {
            return;
        }

        $(document).off('click.erpAjaxDelete', settings.button).on('click.erpAjaxDelete', settings.button, function () {
            var $button = $(this);

            if ($button.data('submitting')) {
                return;
            }

            Swal.fire({
                icon: 'warning',
                text: $button.data('confirm-message') || settings.confirm,
                showCancelButton: true,
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $button.data('submitting', true).prop('disabled', true);

                $.ajax({
                    url: settings.url || $button.data('url'),
                    method: settings.method,
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    }
                }).done(function (response) {
                    showResult(response, 'ลบข้อมูลเรียบร้อย').then(function () {
                        if (response.status === false) {
                            return;
                        }

                        if (settings.redirect && response.redirect) {
                            window.location.assign(response.redirect);
                        } else if (settings.reload === true) {
                            window.location.reload();
                        } else if (typeof settings.reload === 'string') {
                            var $table = $(settings.reload);
                            if ($table.length && $.fn.DataTable.isDataTable($table[0])) {
                                $table.DataTable().ajax.reload(null, false);
                            }
                        }
                    });
                }).fail(function (xhr) {
                    var response = xhr.responseJSON || {};
                    Swal.fire({
                        icon: 'error',
                        text: response.msg || response.message || 'ไม่สามารถลบข้อมูลได้ กรุณาลองใหม่'
                    });
                }).always(function () {
                    $button.data('submitting', false).prop('disabled', false);
                });
            });
        });
    };

    window.erpSelect2Defaults = {
        width: '100%',
        // Keep the menu aligned to the control. Auto-width can make a dropdown
        // expand to the viewport when Select2 is mounted inside a narrow card.
        dropdownAutoWidth: false,
        language: {
            noResults: function () { return 'ไม่พบข้อมูล'; },
            searching: function () { return 'กำลังค้นหา...'; },
            loadingMore: function () { return 'กำลังโหลดเพิ่ม...'; },
            inputTooShort: function () { return 'พิมพ์เพื่อค้นหา'; }
        }
    };

    window.erpInitSelect2 = function (selector, options) {
        var settings = $.extend(true, {}, window.erpSelect2Defaults, options || {});
        return $(selector).filter(function () {
            return !$(this).hasClass('select2-hidden-accessible');
        }).select2(settings);
    };

    $(function () {
        window.erpInitSelect2('.js-select2');
    });
})(jQuery);

// Keep confirmation dialogs visually consistent across modules.
(function () {
    if (!window.Swal || window.Swal.__erpConfirmationDefaults) {
        return;
    }
    var fire = window.Swal.fire.bind(window.Swal);
    window.Swal.fire = function (options) {
        if (options && typeof options === 'object' && options.showCancelButton && !options.icon && !options.toast) {
            options.icon = 'warning';
        }
        if (options && typeof options === 'object' && options.showCancelButton && !options.cancelButtonText) {
            options.cancelButtonText = 'ยกเลิก';
        }
        return fire.apply(window.Swal, arguments);
    };
    window.Swal.__erpConfirmationDefaults = true;
}());
