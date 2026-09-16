(function () {
    'use strict';

    function supportsNativeFileStorage() {
        try {
            return typeof DataTransfer !== 'undefined' && new DataTransfer() instanceof DataTransfer;
        } catch (error) {
            return false;
        }
    }

    function acceptedTypes(input) {
        return (input.getAttribute('accept') || '')
            .split(',')
            .map(function (type) { return type.trim(); })
            .filter(Boolean);
    }

    function initialise() {
        if (!window.FilePond || !supportsNativeFileStorage()) {
            return;
        }

        if (!window.erpFilePondPluginsRegistered) {
            var plugins = [
                window.FilePondPluginFileValidateType,
                window.FilePondPluginFileValidateSize,
                window.FilePondPluginImagePreview
            ].filter(Boolean);

            window.FilePond.registerPlugin.apply(window.FilePond, plugins);
            window.erpFilePondPluginsRegistered = true;
        }

        document.querySelectorAll('[data-platform-file-input]').forEach(function (input) {
            if (input.dataset.filePondInitialised === 'true') {
                return;
            }

            var types = acceptedTypes(input);
            var imagePreview = input.dataset.imagePreview === 'true';
            var uploader = input.closest('[data-platform-file-uploader]');
            var currentPreview = uploader
                ? uploader.querySelector('.platform-file-uploader__current')
                : null;

            var pond = window.FilePond.create(input, {
                storeAsFile: true,
                instantUpload: false,
                allowProcess: false,
                allowRevert: false,
                allowMultiple: input.multiple,
                maxFiles: input.dataset.maxFiles ? Number(input.dataset.maxFiles) : null,
                allowImagePreview: imagePreview,
                imagePreviewMaxHeight: 160,
                imagePreviewTransparencyIndicator: 'grid',
                acceptedFileTypes: types.length ? types : null,
                maxFileSize: input.dataset.maxFileSize || null,
                credits: false,
                dropOnPage: false,
                dropValidation: true,
                labelIdle: 'ลากไฟล์มาวาง หรือ <span class="filepond--label-action">เลือกไฟล์</span>',
                labelFileTypeNotAllowed: 'ไม่รองรับไฟล์ประเภทนี้',
                fileValidateTypeLabelExpectedTypes: 'ไฟล์ที่รองรับ: {allTypes}',
                labelMaxFileSizeExceeded: 'ไฟล์มีขนาดใหญ่เกินกำหนด',
                labelMaxFileSize: 'ขนาดสูงสุด {filesize}',
                labelMaxFileCountExceeded: 'เลือกไฟล์เกินจำนวนที่กำหนด',
                labelMaxFileCount: 'เลือกได้สูงสุด {files} ไฟล์',
                labelFileLoading: 'กำลังอ่านไฟล์',
                labelFileLoadError: 'ไม่สามารถอ่านไฟล์ได้',
                labelFileRemoveError: 'ไม่สามารถนำไฟล์ออกได้',
                labelTapToCancel: 'แตะเพื่อยกเลิก',
                labelTapToRetry: 'แตะเพื่อลองใหม่',
                labelTapToUndo: 'แตะเพื่อย้อนกลับ',
                labelButtonRemoveItem: 'นำไฟล์ออก',
                labelButtonAbortItemLoad: 'ยกเลิก',
                labelButtonRetryItemLoad: 'ลองใหม่'
            });
            input._erpFilePond = pond;

            if (currentPreview) {
                pond.on('addfile', function (error) {
                    if (!error) {
                        currentPreview.hidden = true;
                    }
                });
                pond.on('removefile', function () {
                    currentPreview.hidden = pond.getFiles().length > 0;
                });
            }

            input.dataset.filePondInitialised = 'true';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialise);
    } else {
        initialise();
    }

    window.erpInitialiseFileUploaders = initialise;
}());
