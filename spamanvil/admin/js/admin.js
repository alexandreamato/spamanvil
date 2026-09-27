/* SpamAnvil Admin JS */
(function($) {
    'use strict';

    $(document).ready(function() {
        initRangeSliders();
        initTestConnection();
        initModelPicker();
        initClearKey();
        initUnblockIP();
        initResetPrompt();
        initLoadSpamWords();
        initThresholdSuggestion();
        initScanPending();
        initProcessQueue();
        initDismissNotice();
        initSetupWizard();
        initRecoverySelectAll();
    });

    /**
     * Recovery screen: the header checkbox ticks every row.
     */
    function initRecoverySelectAll() {
        var $all = $('#spamanvil-recovery-all');
        if (!$all.length) {
            return;
        }
        $all.on('change', function() {
            $('input[name="spamanvil_restore[]"]').prop('checked', $(this).is(':checked'));
        });
    }

    /**
     * First-run wizard: verify the pasted key, then reveal the success panel.
     * The key is only stored server-side if the test classification succeeds.
     */
    function initSetupWizard() {
        var $btn = $('#spamanvil-setup-finish');
        if (!$btn.length) {
            return;
        }

        var $key = $('#spamanvil-setup-key');
        var $result = $('#spamanvil-setup-result');
        var $spinner = $('.spamanvil-setup-spinner');

        function finish() {
            var apiKey = $.trim($key.val() || '');

            if (!apiKey) {
                $result.removeClass('success').addClass('error').text(spamAnvil.strings.setup_paste_key);
                $key.trigger('focus');
                return;
            }

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $result.removeClass('success error').text(spamAnvil.strings.setup_testing);

            $.post(spamAnvil.ajax_url, {
                action: 'spamanvil_setup_finish',
                nonce: spamAnvil.nonce,
                provider: 'openrouter',
                api_key: apiKey
            }, function(response) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');

                if (response.success) {
                    $result.addClass('success').text(response.data.message);
                    $('#spamanvil-setup-steps').attr('hidden', true);
                    $('.spamanvil-setup-alt').attr('hidden', true);
                    $('#spamanvil-setup-done').removeAttr('hidden');
                } else {
                    $result.addClass('error').text(response.data);
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                $result.addClass('error').text(spamAnvil.strings.setup_network);
            });
        }

        $btn.on('click', finish);
        $key.on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                finish();
            }
        });
    }

    /**
     * Range slider live value display.
     */
    function initRangeSliders() {
        $('.spamanvil-range').on('input', function() {
            var displayId = $(this).data('display');
            if (displayId) {
                $('#' + displayId).text($(this).val());
            }
        });
    }

    /**
     * Test Connection AJAX.
     */
    function initTestConnection() {
        $('.spamanvil-test-btn').on('click', function() {
            var $btn = $(this);
            var provider = $btn.data('provider');
            var $result = $('.spamanvil-test-result[data-provider="' + provider + '"]');
            var $card = $btn.closest('.spamanvil-card');

            // Read current form values so Test Connection works without saving first.
            var apiKey = $card.find('input[name="spamanvil_' + provider + '_api_key"]').val() || '';
            var model = $card.find('input[name="spamanvil_' + provider + '_model"]').val() || '';
            var apiUrl = $card.find('input[name="spamanvil_generic_api_url"]').val() || '';

            $btn.prop('disabled', true);
            $result.removeClass('success error').text(spamAnvil.strings.testing);

            $.post(spamAnvil.ajax_url, {
                action: 'spamanvil_test_connection',
                nonce: spamAnvil.nonce,
                provider: provider,
                api_key: apiKey,
                model: model,
                api_url: apiUrl
            }, function(response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    var ms = response.data.response_ms || 0;
                    $result.addClass('success').text(
                        spamAnvil.strings.success + ' (' + ms + 'ms)'
                    );
                } else {
                    $result.addClass('error').text(
                        spamAnvil.strings.error + ' ' + response.data
                    );
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                $result.addClass('error').text(spamAnvil.strings.error + ' Network error');
            });
        });
    }

    /**
     * Model picker: fetch a provider's models and let the user search/select one.
     */
    function initModelPicker() {
        $('.spamanvil-browse-models-btn').on('click', function() {
            var provider = $(this).data('provider');
            var $card = $(this).closest('.spamanvil-card');
            var $picker = $card.find('.spamanvil-model-picker[data-provider="' + provider + '"]');

            if ($picker.is(':visible')) {
                $picker.hide();
                return;
            }
            $picker.show();

            if ($picker.data('loaded')) {
                return; // Already fetched this session.
            }

            var $list = $picker.find('.spamanvil-model-list');
            $list.text(spamAnvil.strings.loading_models);

            var apiKey = $card.find('input[name="spamanvil_' + provider + '_api_key"]').val() || '';
            var apiUrl = $card.find('input[name="spamanvil_generic_api_url"]').val() || '';

            $.post(spamAnvil.ajax_url, {
                action: 'spamanvil_list_models',
                nonce: spamAnvil.nonce,
                provider: provider,
                api_key: apiKey,
                api_url: apiUrl
            }, function(response) {
                if (!response.success) {
                    $list.text(spamAnvil.strings.models_error + ' ' + response.data);
                    return;
                }
                $picker.data('models', response.data.models || []);
                $picker.data('loaded', true);
                renderModelList($picker);
            }).fail(function() {
                $list.text(spamAnvil.strings.models_error);
            });
        });

        $('.spamanvil-model-search').on('input', function() {
            renderModelList($(this).closest('.spamanvil-model-picker'));
        });

        $('.spamanvil-free-only-cb').on('change', function() {
            renderModelList($(this).closest('.spamanvil-model-picker'));
        });

        // Select a model → fill the text field. The "+" button appends to the
        // comma-separated model chain instead of replacing it.
        $('.spamanvil-model-list').on('click', '.spamanvil-model-item, .spamanvil-model-add', function(e) {
            var $target = $(e.target);
            var append = $target.hasClass('spamanvil-model-add');
            var $item = $target.closest('.spamanvil-model-item');
            var $picker = $item.closest('.spamanvil-model-picker');
            var provider = $picker.data('provider');
            var id = $item.attr('data-id');
            var $input = $picker.closest('.spamanvil-card')
                .find('input.spamanvil-model-input[data-provider="' + provider + '"]');

            if (append) {
                var current = ($input.val() || '').trim().replace(/,\s*$/, '');
                if (current && current.split(/\s*,\s*/).indexOf(id) !== -1) {
                    return; // Already in the chain.
                }
                $input.val(current ? current + ', ' + id : id);
                return; // Keep the picker open for adding more.
            }

            $input.val(id);
            $picker.hide();
        });
    }

    /**
     * Render (and filter) the model list. Uses .text() for all model-supplied strings.
     */
    function renderModelList($picker) {
        var models = $picker.data('models') || [];
        var q = ($picker.find('.spamanvil-model-search').val() || '').toLowerCase();
        var freeOnly = $picker.find('.spamanvil-free-only-cb').is(':checked');
        var $list = $picker.find('.spamanvil-model-list').empty();
        var shown = 0;

        models.forEach(function(m) {
            if (freeOnly && !m.free) {
                return;
            }
            var hay = (m.id + ' ' + (m.name || '')).toLowerCase();
            if (q && hay.indexOf(q) === -1) {
                return;
            }
            shown++;

            var $item = $('<div class="spamanvil-model-item"></div>').attr('data-id', m.id);
            $('<span class="spamanvil-model-id"></span>').text(m.id).appendTo($item);
            if (m.free) {
                $('<span class="spamanvil-badge spamanvil-badge-free"></span>').text('free').appendTo($item);
            }
            if (m.context) {
                $('<span class="spamanvil-model-context"></span>').text(Math.round(m.context / 1000) + 'k ctx').appendTo($item);
            }
            $('<button type="button" class="button button-small spamanvil-model-add"></button>')
                .text('+')
                .attr('title', spamAnvil.strings.add_to_chain || 'Add to model chain')
                .appendTo($item);
            $item.appendTo($list);
        });

        if (shown === 0) {
            $list.text(spamAnvil.strings.no_models_match);
        }
        $picker.find('.spamanvil-model-count').text(shown + ' / ' + models.length);
    }

    /**
     * Clear API Key AJAX.
     */
    function initClearKey() {
        $('.spamanvil-clear-key-btn').on('click', function() {
            if (!confirm(spamAnvil.strings.confirm_clear_key)) {
                return;
            }

            var $btn = $(this);
            var provider = $btn.data('provider');

            $btn.prop('disabled', true);

            $.post(spamAnvil.ajax_url, {
                action: 'spamanvil_clear_api_key',
                nonce: spamAnvil.nonce,
                provider: provider
            }, function(response) {
                if (response.success) {
                    $btn.closest('td').find('input[type="password"]').val('').attr('placeholder', spamAnvil.strings.enter_key);
                    $btn.closest('td').find('.description').remove();
                    $btn.remove();
                } else {
                    alert(response.data);
                    $btn.prop('disabled', false);
                }
            }).fail(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /**
     * Load extended spam words list.
     */
    function initLoadSpamWords() {
        $('.spamanvil-load-spam-words').on('click', function() {
            if (!confirm(spamAnvil.strings.confirm_load_words)) {
                return;
            }

            var words = [
                "buy now", "click here", "free money", "earn money", "make money online",
                "work from home", "casino", "poker", "lottery", "lotto", "togel",
                "viagra", "cialis", "pharmacy", "cheap pills", "diet pills", "weight loss",
                "crypto", "bitcoin investment", "forex trading", "seo services",
                "backlinks", "link building", "payday loan", "adult content",
                "xxx", "porn", "dating site", "meet singles",
                "slot online", "slot gacor", "judi online", "live draw", "prediksi",
                "bocoran", "bandar togel", "agen judi", "taruhan", "jackpot",
                "pengeluaran", "keluaran", "paito", "toto", "result sgp",
                "layarkaca", "nonton online", "download film", "indoxxi", "drakor",
                "streaming movie", "subtitle indonesia", "ganool", "rebahin",
                "replica watches", "cheap designer", "fake rolex", "ugg boots",
                "ray ban", "louis vuitton", "gucci outlet", "nike factory",
                "essay writing", "write my essay", "assignment help", "homework help",
                "term paper", "dissertation help", "coursework help",
                "instagram followers", "buy followers", "buy likes", "social media marketing",
                "get rich quick", "double your money", "guaranteed income",
                "act now", "limited time", "order now", "special promotion",
                "exclusive deal", "risk free", "no obligation", "100% free",
                "miracle cure", "amazing results", "breakthrough", "secret revealed",
                "as seen on", "celebrity endorsed", "doctor recommended",
                "enlarge", "enhancement", "testosterone", "cbd oil", "keto",
                "web hosting deal", "cheap hosting", "vpn deal", "antivirus deal",
                "windows key", "office key", "software license", "crack download",
                "keygen", "serial key", "activation code", "nulled",
                "call girl", "escort service", "hookup", "one night stand",
                "spy software", "hack account", "password crack",
                "debt relief", "credit repair", "tax relief", "lawsuit",
                "mesothelioma", "asbestos", "personal injury lawyer"
            ];

            var $textarea = $('textarea[name="spamanvil_spam_words"]');
            var current = $textarea.val().trim();

            if (current) {
                // Merge: add only words not already present.
                var existing = current.toLowerCase().split("\n").map(function(w) { return w.trim(); });
                var added = 0;
                var newWords = current;
                for (var i = 0; i < words.length; i++) {
                    if (existing.indexOf(words[i].toLowerCase()) === -1) {
                        newWords += "\n" + words[i];
                        added++;
                    }
                }
                $textarea.val(newWords);
                $(this).replaceWith('<span class="description"><strong>' + added + ' ' + spamAnvil.strings.words_added + '</strong></span>');
            } else {
                $textarea.val(words.join("\n"));
                $(this).replaceWith('<span class="description"><strong>' + spamAnvil.strings.words_loaded + '</strong></span>');
            }
        });
    }

    /**
     * Unblock IP AJAX.
     */
    function initUnblockIP() {
        $('.spamanvil-unblock-btn').on('click', function() {
            if (!confirm(spamAnvil.strings.confirm)) {
                return;
            }

            var $btn = $(this);
            var id = $btn.data('id');

            $btn.prop('disabled', true).text(spamAnvil.strings.unblocking);

            $.post(spamAnvil.ajax_url, {
                action: 'spamanvil_unblock_ip',
                nonce: spamAnvil.nonce,
                id: id
            }, function(response) {
                if (response.success) {
                    $btn.closest('tr').fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    $btn.prop('disabled', false).text('Remove');
                    alert(response.data);
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Remove');
            });
        });
    }

    /**
     * Reset prompt to default.
     */
    function initResetPrompt() {
        var defaults = spamAnvil.default_prompts || {};

        $('.spamanvil-reset-prompt').on('click', function() {
            var target = $(this).data('target');
            var defaultType = $(this).data('default');

            if (defaults[defaultType] && confirm(spamAnvil.strings.confirm)) {
                $('textarea[name="' + target + '"]').val(defaults[defaultType]);
            }
        });
    }

    /**
     * Apply threshold suggestion button.
     */
    function initThresholdSuggestion() {
        $('.spamanvil-apply-suggestion').on('click', function() {
            var value = $(this).data('value');
            var $slider = $('input[name="spamanvil_threshold"]');
            $slider.val(value).trigger('input');
            $(this).replaceWith('<span class="description"><strong>' + spamAnvil.strings.applied + '</strong></span>');
        });
    }

    /**
     * Process Queue Now AJAX (loops batches until empty).
     * Features: time-guarded batches, auto-retry, progress bar, stop button.
     */
    function initProcessQueue() {
        var totalProcessed = 0;
        var totalItems     = 0;
        var totalSpam      = 0;
        var totalHam       = 0;
        var startTime      = 0;
        var stopped        = false;
        var retryCount     = 0;
        var maxRetries     = 3;
        var retryDelay     = 2000;
        var currentXhr     = null;

        var $btn     = $('.spamanvil-process-queue-btn');
        var $stopBtn = $('.spamanvil-stop-queue-btn');
        var $result  = $('.spamanvil-process-queue-result');
        var $wrap    = $('.spamanvil-progress-wrap');
        var $fill    = $('.spamanvil-progress-fill');
        var $text    = $('.spamanvil-progress-text');
        var $details = $('.spamanvil-progress-details');

        $btn.on('click', function() {
            if (!spamAnvil.has_provider) {
                $result.addClass('error').html(
                    spamAnvil.strings.no_provider +
                    ' <a href="' + spamAnvil.providers_url + '">' +
                    spamAnvil.strings.configure_provider + ' &rarr;</a>'
                );
                return;
            }

            totalProcessed = 0;
            totalSpam      = 0;
            totalHam       = 0;
            retryCount     = 0;
            stopped        = false;
            startTime      = Date.now();

            // Calculate total from queue counters.
            var $items = $('.spamanvil-status-grid .status-item');
            totalItems = 0;
            if ($items.length >= 4) {
                totalItems += parseInt($items.eq(0).find('.status-number').text(), 10) || 0;
                totalItems += parseInt($items.eq(2).find('.status-number').text(), 10) || 0;
                totalItems += parseInt($items.eq(3).find('.status-number').text(), 10) || 0;
            }

            $btn.prop('disabled', true).hide();
            $stopBtn.show();
            $result.removeClass('success error').text(spamAnvil.strings.processing);
            $wrap.show();
            updateProgress(0, totalItems);

            processBatch();
        });

        $stopBtn.on('click', function() {
            stopped = true;
            $stopBtn.prop('disabled', true).text(spamAnvil.strings.process_stopping);
            if (currentXhr) {
                currentXhr.abort();
            }
        });

        function processBatch() {
            if (stopped) {
                finish(spamAnvil.strings.process_stopped + ' ' + totalProcessed + ' processed.');
                return;
            }

            currentXhr = $.ajax({
                url: spamAnvil.ajax_url,
                type: 'POST',
                timeout: 45000,
                data: {
                    action: 'spamanvil_process_queue',
                    nonce: spamAnvil.nonce
                },
                success: function(response) {
                    currentXhr = null;
                    retryCount = 0;

                    if (!response.success) {
                        finish(response.data, true);
                        return;
                    }

                    var d = response.data;
                    totalProcessed += d.processed;
                    totalSpam      += d.batch_spam || 0;
                    totalHam       += d.batch_ham  || 0;

                    // Recalculate total if server reports more items than expected.
                    if (totalProcessed + d.remaining > totalItems) {
                        totalItems = totalProcessed + d.remaining;
                    }

                    updateProgress(totalProcessed, totalProcessed + d.remaining);
                    updateQueueCounters(d.queue);
                    updateSpamCounters(d.alltime);

                    if (stopped) {
                        finish(spamAnvil.strings.process_stopped + ' ' + totalProcessed + ' processed, ' + d.remaining + ' remaining.');
                        return;
                    }

                    if (d.remaining > 0 && d.processed > 0) {
                        $result.text(
                            spamAnvil.strings.process_batch +
                            ' ' + totalProcessed + ' processed, ' +
                            d.remaining + ' remaining...'
                        );
                        processBatch();
                    } else if (d.remaining > 0 && d.attempted > 0 && d.processed === 0) {
                        finish(
                            totalProcessed + ' processed, ' +
                            d.remaining + ' remaining. ' +
                            spamAnvil.strings.batch_all_failed,
                            true
                        );
                    } else {
                        finish(
                            spamAnvil.strings.process_done +
                            ' ' + totalProcessed + ' processed, ' +
                            d.remaining + ' remaining.'
                        );
                    }
                },
                error: function(xhr, status) {
                    currentXhr = null;

                    if (stopped || status === 'abort') {
                        finish(spamAnvil.strings.process_stopped + ' ' + totalProcessed + ' processed.');
                        return;
                    }

                    retryCount++;
                    if (retryCount <= maxRetries) {
                        $result.text(
                            spamAnvil.strings.process_retrying +
                            ' (' + retryCount + '/' + maxRetries + ')'
                        );
                        setTimeout(processBatch, retryDelay);
                    } else {
                        finish(spamAnvil.strings.process_failed + ' ' + totalProcessed + ' processed.', true);
                    }
                }
            });
        }

        function updateProgress(processed, total) {
            var pct = total > 0 ? Math.round((processed / total) * 100) : 0;
            $fill.css('width', pct + '%');
            $text.text(processed + ' / ' + total + ' (' + pct + '%)');

            // Speed and elapsed time.
            var elapsed = (Date.now() - startTime) / 1000;
            var speed   = elapsed > 0 ? (processed / elapsed * 60).toFixed(1) : 0;
            var mins    = Math.floor(elapsed / 60);
            var secs    = Math.floor(elapsed % 60);
            var time    = (mins > 0 ? mins + 'm ' : '') + secs + 's';

            // Spam/ham results.
            var results = '';
            if (totalSpam > 0 || totalHam > 0) {
                results = ' — ' + spamAnvil.strings.spam + ': ' + totalSpam + ' | ' + spamAnvil.strings.ham + ': ' + totalHam;
            }

            $details.text(speed + ' ' + spamAnvil.strings.items_min + ' — ' + time + results);
        }

        function finish(message, isError) {
            $btn.prop('disabled', false).show();
            $stopBtn.hide().prop('disabled', false).text(spamAnvil.strings.process_stop);
            currentXhr = null;
            if (isError) {
                $result.addClass('error').text(message);
            } else {
                $result.addClass('success').text(message);
            }
        }

        function updateQueueCounters(queue) {
            var $items = $('.spamanvil-status-grid .status-item');
            if ($items.length >= 4 && queue) {
                $items.eq(0).find('.status-number').text(queue.queued);
                $items.eq(1).find('.status-number').text(queue.processing);
                $items.eq(2).find('.status-number').text(queue.failed);
                $items.eq(3).find('.status-number').text(queue.max_retries);
            }
        }

        function updateSpamCounters(alltime) {
            if (!alltime) { return; }
            var total = (alltime.ai || 0) + (alltime.heuristic || 0) + (alltime.ip || 0);

            // Update hero banner(s).
            $('.spamanvil-hero-number').text(total.toLocaleString());

            // Update widget number (WP dashboard).
            $('.spamanvil-widget-number').text(total.toLocaleString());
        }
    }

    /**
     * Persistent notice dismissal via AJAX.
     */
    function initDismissNotice() {
        function dismiss(noticeKey, $container) {
            $.post(spamAnvil.ajax_url, {
                action: 'spamanvil_dismiss_notice',
                nonce: spamAnvil.nonce,
                notice: noticeKey
            });
            $container.fadeTo(100, 0, function() {
                $(this).slideUp(100, function() {
                    $(this).remove();
                });
            });
        }

        // "No thanks" button.
        $('.spamanvil-dismiss-btn').on('click', function() {
            var noticeKey = $(this).data('notice');
            dismiss(noticeKey, $(this).closest('.notice'));
        });

        // WordPress dismiss button (X) on our notices.
        $(document).on('click', '.spamanvil-dismissible .notice-dismiss', function() {
            var noticeKey = $(this).closest('.spamanvil-dismissible').data('notice');
            if (noticeKey) {
                $.post(spamAnvil.ajax_url, {
                    action: 'spamanvil_dismiss_notice',
                    nonce: spamAnvil.nonce,
                    notice: noticeKey
                });
            }
        });
    }

    /**
     * Scan Pending Comments AJAX.
     */
    function initScanPending() {
        $('.spamanvil-scan-pending-btn').on('click', function() {
            var $btn = $(this);
            var $result = $('.spamanvil-scan-pending-result');

            if (!spamAnvil.has_provider) {
                $result.addClass('error').html(
                    spamAnvil.strings.no_provider +
                    ' <a href="' + spamAnvil.providers_url + '">' +
                    spamAnvil.strings.configure_provider + ' &rarr;</a>'
                );
                return;
            }

            $btn.prop('disabled', true);
            $result.removeClass('success error').text(spamAnvil.strings.scanning);

            $.ajax({
                url: spamAnvil.ajax_url,
                type: 'POST',
                timeout: 60000,
                data: {
                    action: 'spamanvil_scan_pending',
                    nonce: spamAnvil.nonce
                },
                success: function(response) {
                    $btn.prop('disabled', false);

                    if (response.success) {
                        var d = response.data;
                        $result.addClass('success').text(
                            spamAnvil.strings.scan_done +
                            ' ' + d.enqueued + ' enqueued, ' +
                            d.auto_spam + ' auto-spam, ' +
                            d.already_queued + ' already queued.'
                        );

                        // Enable "Process Queue Now" and update queued counter.
                        if (d.enqueued > 0) {
                            $('.spamanvil-process-queue-btn').prop('disabled', false);
                            var $queued = $('.spamanvil-status-grid .status-item').eq(0).find('.status-number');
                            var current = parseInt($queued.text(), 10) || 0;
                            $queued.text(current + d.enqueued);
                        }
                    } else {
                        $result.addClass('error').text(response.data);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $result.addClass('error').text(spamAnvil.strings.error + ' Network error');
                }
            });
        });
    }

})(jQuery);
