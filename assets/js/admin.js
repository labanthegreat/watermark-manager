/**
 * Watermark Manager - Admin JavaScript
 *
 * Handles batch processing AJAX, media selector, live preview,
 * template management, backup browser, and UI interactions.
 *
 * @package WatermarkManager
 */

/* global jQuery, wp, wmAdmin */

(function ($) {
	'use strict';

	/**
	 * Utility: Debounce function.
	 */
	function debounce(fn, delay) {
		let timer;
		return function () {
			clearTimeout(timer);
			timer = setTimeout(fn, delay);
		};
	}

	/**
	 * Toast notification system (replaces alert()).
	 * Now includes icons and undo capability.
	 */
	const Toast = {
		$container: null,

		ICONS: {
			success: '<span class="wm-toast-icon">&#10003;</span>',
			error: '<span class="wm-toast-icon">&#33;</span>',
			warning: '<span class="wm-toast-icon">&#9888;</span>',
			info: '<span class="wm-toast-icon">i</span>',
		},

		init: function () {
			if (!$('.wm-toast-container').length) {
				$('body').append('<div class="wm-toast-container"></div>');
			}
			this.$container = $('.wm-toast-container');
		},

		show: function (message, type, duration, options) {
			if (!this.$container) {
				this.init();
			}

			type = type || 'info';
			duration = duration || 4000;
			options = options || {};

			var icon = this.ICONS[type] || this.ICONS.info;
			var undoHtml = '';
			if (options.undoCallback) {
				undoHtml = '<button type="button" class="wm-toast-undo">' + wmAdmin.strings.undo + '</button>';
			}

			var $toast = $(
				'<div class="wm-toast wm-toast-' + type + '">' +
					icon +
					'<span class="wm-toast-message">' + $('<span>').text(message).html() + undoHtml + '</span>' +
					'<button type="button" class="wm-toast-close">&times;</button>' +
				'</div>'
			);

			this.$container.append($toast);

			$toast.find('.wm-toast-close').on('click', function () {
				Toast.dismiss($toast);
			});

			if (options.undoCallback) {
				$toast.find('.wm-toast-undo').on('click', function () {
					options.undoCallback();
					Toast.dismiss($toast);
				});
			}

			if (duration > 0) {
				setTimeout(function () {
					Toast.dismiss($toast);
				}, duration);
			}

			return $toast;
		},

		dismiss: function ($toast) {
			if ($toast.hasClass('wm-toast-out')) {
				return;
			}
			$toast.addClass('wm-toast-out');
			setTimeout(function () {
				$toast.remove();
			}, 300);
		},

		success: function (msg, duration, options) {
			return this.show(msg, 'success', duration, options);
		},

		error: function (msg, duration, options) {
			return this.show(msg, 'error', duration || 6000, options);
		},

		warning: function (msg, duration, options) {
			return this.show(msg, 'warning', duration, options);
		},

		info: function (msg, duration, options) {
			return this.show(msg, 'info', duration, options);
		},
	};

	/**
	 * Custom confirm dialog (replaces window.confirm()).
	 */
	const ConfirmDialog = {
		show: function (message, onConfirm, onCancel) {
			var $modal = $(
				'<div class="wm-modal wm-confirm-modal">' +
					'<div class="wm-modal-overlay"></div>' +
					'<div class="wm-modal-content">' +
						'<div class="wm-confirm-body">' +
							'<span class="wm-confirm-icon dashicons dashicons-warning"></span>' +
							'<p>' + $('<span>').text(message).html() + '</p>' +
						'</div>' +
						'<div class="wm-confirm-buttons">' +
							'<button type="button" class="button wm-confirm-cancel">' + wmAdmin.strings.cancel + '</button>' +
							'<button type="button" class="button button-primary wm-confirm-ok">' + wmAdmin.strings.confirm + '</button>' +
						'</div>' +
					'</div>' +
				'</div>'
			);

			$('body').append($modal);

			$modal.find('.wm-confirm-ok').on('click', function () {
				$modal.remove();
				if (typeof onConfirm === 'function') {
					onConfirm();
				}
			});

			$modal.find('.wm-confirm-cancel, .wm-modal-overlay').on('click', function () {
				$modal.remove();
				if (typeof onCancel === 'function') {
					onCancel();
				}
			});

			// Escape key closes confirm dialog.
			var escHandler = function (e) {
				if (e.key === 'Escape') {
					$modal.remove();
					$(document).off('keydown', escHandler);
					if (typeof onCancel === 'function') {
						onCancel();
					}
				}
			};
			$(document).on('keydown', escHandler);

			// Focus the confirm button.
			setTimeout(function () {
				$modal.find('.wm-confirm-ok').focus();
			}, 100);
		},
	};

	/**
	 * Button loading state helpers.
	 */
	const ButtonLoading = {
		start: function ($btn) {
			if ($btn.hasClass('is-loading')) {
				return;
			}
			$btn.data('wm-orig-text', $btn.text());
			$btn.addClass('is-loading').prop('disabled', true);
		},

		stop: function ($btn, text) {
			$btn.removeClass('is-loading').prop('disabled', false);
			if (text) {
				$btn.text(text);
			} else if ($btn.data('wm-orig-text')) {
				$btn.text($btn.data('wm-orig-text'));
			}
		},
	};

	/**
	 * Live Preview renderer using Canvas.
	 * Uses debounced updates for better performance.
	 */
	const LivePreview = {
		canvas: null,
		ctx: null,
		sampleImg: null,

		init: function () {
			this.canvas = document.getElementById('wm-preview-canvas');
			if (!this.canvas) {
				return;
			}

			this.ctx = this.canvas.getContext('2d');
			this.drawSample();

			// Bind to setting changes with debouncing for performance.
			var debouncedRender = debounce(this.render.bind(this), 150);
			$(document).on('input change', '.wm-setting-input', debouncedRender);
		},

		drawSample: function () {
			var w = this.canvas.width;
			var h = this.canvas.height;
			var ctx = this.ctx;

			// Draw a sample landscape.
			var gradient = ctx.createLinearGradient(0, 0, 0, h);
			gradient.addColorStop(0, '#87CEEB');
			gradient.addColorStop(0.6, '#98D8E8');
			gradient.addColorStop(0.6, '#4CAF50');
			gradient.addColorStop(1, '#2E7D32');
			ctx.fillStyle = gradient;
			ctx.fillRect(0, 0, w, h);

			// Draw sun.
			ctx.beginPath();
			ctx.arc(w * 0.8, h * 0.2, 30, 0, Math.PI * 2);
			ctx.fillStyle = '#FFD700';
			ctx.fill();

			// Draw some mountains.
			ctx.beginPath();
			ctx.moveTo(0, h * 0.6);
			ctx.lineTo(w * 0.3, h * 0.3);
			ctx.lineTo(w * 0.6, h * 0.6);
			ctx.fillStyle = '#5D8A5D';
			ctx.fill();

			ctx.beginPath();
			ctx.moveTo(w * 0.3, h * 0.6);
			ctx.lineTo(w * 0.7, h * 0.25);
			ctx.lineTo(w, h * 0.6);
			ctx.fillStyle = '#4A7A4A';
			ctx.fill();

			// Store the base image.
			this.sampleImg = ctx.getImageData(0, 0, w, h);

			this.render();
		},

		render: function () {
			if (!this.canvas || !this.ctx || !this.sampleImg) {
				return;
			}

			var ctx = this.ctx;
			var w = this.canvas.width;
			var h = this.canvas.height;

			// Restore base image.
			ctx.putImageData(this.sampleImg, 0, 0);

			// Read current settings.
			var settings = this.getSettings();

			if (settings.watermark_type !== 'text') {
				// For image watermarks, show placeholder.
				this.drawPlaceholderWatermark(ctx, w, h, settings);
				return;
			}

			var text = settings.text_content || 'Sample';
			var opacity = (settings.opacity || 50) / 100;
			var rotation = -(settings.rotation || 0) * (Math.PI / 180);
			var fontSize = Math.max(8, Math.round((settings.font_size || 24) * (w / 1000)));
			var color = settings.font_color || '#ffffff';
			var padding = settings.padding || 20;
			var scaledPadding = Math.round(padding * (w / 1000));

			ctx.save();
			ctx.globalAlpha = opacity;
			ctx.font = fontSize + 'px sans-serif';
			ctx.fillStyle = color;

			if (settings.tiling) {
				this.drawTiledText(ctx, w, h, text, fontSize, rotation, settings.tile_spacing || 100);
			} else {
				var metrics = ctx.measureText(text);
				var textW = metrics.width;
				var textH = fontSize;
				var pos = this.calcPosition(w, h, textW, textH, settings.position, scaledPadding);

				ctx.translate(pos.x + textW / 2, pos.y + textH / 2);
				ctx.rotate(rotation);
				ctx.fillText(text, -textW / 2, textH / 2 - fontSize * 0.15);
			}

			ctx.restore();
		},

		drawTiledText: function (ctx, w, h, text, fontSize, rotation, spacing) {
			var metrics = ctx.measureText(text);
			var textW = metrics.width;
			var textH = fontSize;
			var scaledSpacing = Math.round(spacing * (w / 1000));
			var stepX = textW + scaledSpacing;
			var stepY = textH + scaledSpacing;
			var margin = Math.max(w, h);

			for (var y = -margin; y < h + margin; y += stepY) {
				for (var x = -margin; x < w + margin; x += stepX) {
					ctx.save();
					ctx.translate(x + textW / 2, y + textH / 2);
					ctx.rotate(rotation);
					ctx.fillText(text, -textW / 2, textH / 2);
					ctx.restore();
				}
			}
		},

		drawPlaceholderWatermark: function (ctx, w, h, settings) {
			var opacity = (settings.opacity || 50) / 100;
			var scale = (settings.scale || 25) / 100;
			var padding = Math.round((settings.padding || 20) * (w / 1000));

			var wmW = Math.round(w * scale);
			var wmH = Math.round(wmW * 0.6);

			var pos = this.calcPosition(w, h, wmW, wmH, settings.position, padding);

			ctx.save();
			ctx.globalAlpha = opacity;
			ctx.strokeStyle = '#ffffff';
			ctx.lineWidth = 2;
			ctx.setLineDash([5, 5]);
			ctx.strokeRect(pos.x, pos.y, wmW, wmH);
			ctx.fillStyle = 'rgba(255,255,255,0.2)';
			ctx.fillRect(pos.x, pos.y, wmW, wmH);

			ctx.fillStyle = '#ffffff';
			ctx.font = '12px sans-serif';
			ctx.textAlign = 'center';
			ctx.fillText('Image Watermark', pos.x + wmW / 2, pos.y + wmH / 2 + 4);
			ctx.restore();
		},

		calcPosition: function (cw, ch, ew, eh, position, padding) {
			var pad = padding || 0;

			switch (position) {
				case 'top-left':
					return { x: pad, y: pad };
				case 'top-right':
					return { x: cw - ew - pad, y: pad };
				case 'center':
					return { x: (cw - ew) / 2, y: (ch - eh) / 2 };
				case 'bottom-left':
					return { x: pad, y: ch - eh - pad };
				case 'bottom-right':
				default:
					return { x: cw - ew - pad, y: ch - eh - pad };
			}
		},

		getSettings: function () {
			var s = {};
			s.watermark_type = $('input[name="wm_settings[watermark_type]"]:checked').val() || 'text';
			s.text_content = $('input[name="wm_settings[text_content]"]').val() || '';
			s.font_size = parseInt($('input[name="wm_settings[font_size]"]').val(), 10) || 24;
			s.font_color = $('input[name="wm_settings[font_color]"]').val() || '#ffffff';
			s.position = $('#wm-position-input').val() || 'bottom-right';
			s.opacity = parseInt($('#wm-opacity-range').val(), 10) || 50;
			s.scale = parseInt($('#wm-scale-range').val(), 10) || 25;
			s.rotation = parseInt($('#wm-rotation-range').val(), 10) || 0;
			s.padding = parseInt($('input[name="wm_settings[padding]"]').val(), 10) || 20;
			s.tiling = $('input[name="wm_settings[tiling]"]').is(':checked');
			s.tile_spacing = parseInt($('input[name="wm_settings[tile_spacing]"]').val(), 10) || 100;
			return s;
		},
	};

	/**
	 * Batch Processor module.
	 * Includes estimated time remaining.
	 */
	const BatchProcessor = {
		totalImages: 0,
		processed: 0,
		skipped: 0,
		failed: 0,
		running: false,
		startTime: null,
		lastProcessedIds: [],

		init: function () {
			$('#wm-batch-start').on('click', this.start.bind(this));
			$('#wm-batch-cancel').on('click', this.cancel.bind(this));
			$('#wm-batch-dry-run').on('click', this.dryRun.bind(this));
			$('#wm-retry-failed').on('click', this.retryFailed.bind(this));

			this.loadErrorLog();
		},

		getFilters: function () {
			return {
				date_after: $('#wm-filter-date-after').val() || '',
				date_before: $('#wm-filter-date-before').val() || '',
				mime_type: $('#wm-filter-mime-type').val() || '',
				min_width: $('#wm-filter-min-width').val() || '',
				min_height: $('#wm-filter-min-height').val() || '',
			};
		},

		start: function () {
			if (this.running) {
				return;
			}

			var self = this;
			ConfirmDialog.show(wmAdmin.strings.confirmBatch, function () {
				self.running = true;
				self.processed = 0;
				self.skipped = 0;
				self.failed = 0;
				self.startTime = Date.now();
				self.lastProcessedIds = [];

				var $btn = $('#wm-batch-start');
				ButtonLoading.start($btn);
				$btn.text(wmAdmin.strings.processing);
				$('#wm-batch-cancel').show();
				$('#wm-batch-progress').show().addClass('wm-fade-in');
				$('#wm-dry-run-results').hide();
				$('.wm-progress-log').empty();

				self.fetchTotal();
			});
		},

		cancel: function () {
			var $btn = $('#wm-batch-cancel');
			ButtonLoading.start($btn);

			$.post(wmAdmin.ajaxUrl, {
				action: 'wm_batch_cancel',
				nonce: wmAdmin.batchNonce,
			}).done(function () {
				BatchProcessor.running = false;
				BatchProcessor.log(wmAdmin.strings.cancelled, 'error');
				BatchProcessor.finish();
			}).always(function () {
				ButtonLoading.stop($btn);
			});
		},

		dryRun: function () {
			var filters = this.getFilters();
			var $btn = $('#wm-batch-dry-run');
			ButtonLoading.start($btn);

			$.post(wmAdmin.ajaxUrl, Object.assign({
				action: 'wm_batch_dry_run',
				nonce: wmAdmin.batchNonce,
			}, filters))
				.done(function (response) {
					if (!response.success) {
						return;
					}

					var data = response.data;
					$('.wm-dry-run-total').text(
						wmAdmin.strings.imagesWouldProcess.replace('%d', data.total)
					);

					var $list = $('.wm-dry-run-list').empty();
					(data.items || []).forEach(function (item) {
						var $card = $('<div class="wm-dry-run-item">');
						if (item.thumbnail) {
							$card.append($('<img>').attr('src', item.thumbnail));
						}
						$card.append(
							$('<div class="wm-dry-info">').append(
								$('<div class="wm-dry-title">').text(item.title),
								$('<div class="wm-dry-meta">').text(item.dimensions + ' / ' + item.size)
							)
						);
						$list.append($card);
					});

					if (data.total > data.items.length) {
						$list.append(
							$('<div class="wm-dry-run-item">').text(
								wmAdmin.strings.andMore.replace('%d', data.total - data.items.length)
							)
						);
					}

					$('#wm-dry-run-results').show().addClass('wm-fade-in');
				})
				.always(function () {
					ButtonLoading.stop($btn, wmAdmin.strings.dryRun);
				});
		},

		retryFailed: function () {
			var $btn = $('#wm-retry-failed');
			ButtonLoading.start($btn);

			$.post(wmAdmin.ajaxUrl, {
				action: 'wm_batch_retry_failed',
				nonce: wmAdmin.batchNonce,
			}).done(function (response) {
				if (response.success) {
					Toast.success(response.data.message);
					$('#wm-error-log').empty();
				}
			}).always(function () {
				ButtonLoading.stop($btn);
			});
		},

		loadErrorLog: function () {
			if (!$('#wm-error-log').length) {
				return;
			}

			$.post(wmAdmin.ajaxUrl, {
				action: 'wm_batch_error_log',
				nonce: wmAdmin.batchNonce,
			}).done(function (response) {
				if (!response.success || !response.data.length) {
					$('#wm-error-log').html('<em>' + wmAdmin.strings.noErrorsRecorded + '</em>');
					return;
				}

				var $log = $('#wm-error-log').empty();
				response.data.forEach(function (err) {
					$log.append(
						$('<div class="wm-error-entry">').append(
							$('<span class="wm-error-time">').text(err.timestamp),
							$('<span>').text('#' + err.attachment_id + ': ' + err.message)
						)
					);
				});
			});
		},

		fetchTotal: function () {
			var filters = this.getFilters();

			$.post(wmAdmin.ajaxUrl, Object.assign({
				action: 'wm_batch_start',
				nonce: wmAdmin.batchNonce,
			}, filters))
				.done(function (response) {
					if (response.success) {
						BatchProcessor.totalImages = response.data.total;
						BatchProcessor.updateUI();

						if (BatchProcessor.totalImages === 0) {
							BatchProcessor.log(wmAdmin.strings.noUnwatermarked, 'success');
							BatchProcessor.finish();
							return;
						}

						BatchProcessor.processNext();
					} else {
						BatchProcessor.log(response.data || wmAdmin.strings.error, 'error');
						BatchProcessor.finish();
					}
				})
				.fail(function () {
					BatchProcessor.log(wmAdmin.strings.error, 'error');
					BatchProcessor.finish();
				});
		},

		processNext: function () {
			if (!this.running) {
				return;
			}

			var batchSize = parseInt($('#wm-batch-size').val(), 10) || 10;

			$.post(wmAdmin.ajaxUrl, {
				action: 'wm_batch_process',
				nonce: wmAdmin.batchNonce,
				batch_size: batchSize,
			})
				.done(function (response) {
					if (!response.success) {
						BatchProcessor.log(response.data || wmAdmin.strings.error, 'error');
						BatchProcessor.finish();
						return;
					}

					var data = response.data;

					if (data.cancelled) {
						BatchProcessor.log(wmAdmin.strings.cancelled, 'error');
						BatchProcessor.finish();
						return;
					}

					BatchProcessor.processed += data.processed;
					BatchProcessor.skipped += data.skipped;
					BatchProcessor.failed += data.failed;

					// Track processed IDs for undo capability.
					if (data.processed_ids) {
						BatchProcessor.lastProcessedIds = BatchProcessor.lastProcessedIds.concat(data.processed_ids);
					}

					if (data.errors && data.errors.length) {
						data.errors.forEach(function (err) {
							BatchProcessor.log(err, 'error');
						});
					}

					if (data.processed > 0) {
						BatchProcessor.log(
							wmAdmin.strings.imagesWatermarked.replace('%d', data.processed),
							'success'
						);
					}

					BatchProcessor.updateUI();

					if (data.remaining > 0) {
						BatchProcessor.processNext();
					} else {
						BatchProcessor.log(wmAdmin.strings.complete, 'success');
						BatchProcessor.finish();

						// Show toast with undo for recent batch.
						if (BatchProcessor.lastProcessedIds.length > 0) {
							Toast.success(
								wmAdmin.strings.completeCount.replace('%d', BatchProcessor.processed),
								8000,
								{
									undoCallback: function () {
										BatchProcessor.undoLastBatch();
									},
								}
							);
						}
					}
				})
				.fail(function () {
					BatchProcessor.log(wmAdmin.strings.error, 'error');
					BatchProcessor.finish();
				});
		},

		updateUI: function () {
			var done = this.processed + this.skipped + this.failed;
			var total = Math.max(done + this.getRemainingFromStats(), 1);
			var pct = Math.min(100, Math.round((done / total) * 100));

			$('.wm-progress-fill').css('width', pct + '%');
			$('.wm-stat-processed').text(this.processed);
			$('.wm-stat-skipped').text(this.skipped);
			$('.wm-stat-failed').text(this.failed);
			$('.wm-stat-remaining').text(Math.max(0, this.totalImages - done));

			// ETA calculation with better formatting.
			if (this.startTime && done > 0) {
				var elapsed = (Date.now() - this.startTime) / 1000;
				var rate = done / elapsed;
				var remaining = Math.max(0, this.totalImages - done);
				var etaSeconds = Math.round(remaining / rate);

				if (etaSeconds > 0) {
					var hours = Math.floor(etaSeconds / 3600);
					var mins = Math.floor((etaSeconds % 3600) / 60);
					var secs = etaSeconds % 60;
					var etaStr = wmAdmin.strings.eta;
					if (hours > 0) {
						etaStr += hours + 'h ';
					}
					if (mins > 0) {
						etaStr += mins + 'm ';
					}
					etaStr += secs + 's';
					$('.wm-stat-eta').text(etaStr);
				} else {
					$('.wm-stat-eta').text('');
				}
			}
		},

		getRemainingFromStats: function () {
			var done = this.processed + this.skipped + this.failed;
			return Math.max(0, this.totalImages - done);
		},

		log: function (message, type) {
			var cls = type === 'error' ? 'wm-log-error' : 'wm-log-success';
			$('.wm-progress-log').append(
				$('<p>').addClass(cls).text('[' + new Date().toLocaleTimeString() + '] ' + message)
			);

			// Auto-scroll.
			var logEl = $('.wm-progress-log')[0];
			if (logEl) {
				logEl.scrollTop = logEl.scrollHeight;
			}
		},

		finish: function () {
			this.running = false;
			ButtonLoading.stop($('#wm-batch-start'), wmAdmin.strings.startBatch);
			$('#wm-batch-cancel').hide();
			$('.wm-stat-eta').text('');
			this.loadErrorLog();
		},

		undoLastBatch: function () {
			if (!this.lastProcessedIds.length) {
				Toast.warning(wmAdmin.strings.noRecentBatch);
				return;
			}

			Toast.info(wmAdmin.strings.restoringImages.replace('%d', this.lastProcessedIds.length));

			$.post(wmAdmin.ajaxUrl, {
				action: 'wm_batch_undo',
				nonce: wmAdmin.batchNonce,
				attachment_ids: this.lastProcessedIds,
			}).done(function (response) {
				if (response && response.success) {
					Toast.success(wmAdmin.strings.batchUndoComplete.replace('%d', response.data.restored || 0));
					BatchProcessor.lastProcessedIds = [];
				} else {
					Toast.error(wmAdmin.strings.undoFailedReason.replace('%s', (response && response.data) || wmAdmin.strings.error));
				}
			}).fail(function () {
				Toast.error(wmAdmin.strings.undoRequestFailed);
			});
		},
	};

	/**
	 * Template Manager module.
	 */
	const TemplateManager = {
		templates: [],

		init: function () {
			this.templates = wmAdmin.templates || [];
			this.renderGrid();

			$('#wm-add-template').on('click', this.openNewModal.bind(this));
			$(document).on('click', '.wm-tpl-edit', this.openEditModal.bind(this));
			$(document).on('click', '.wm-tpl-delete', this.deleteTemplate.bind(this));
			$(document).on('click', '.wm-modal-close, .wm-modal-overlay', this.closeModal);
			$('#wm-tpl-save').on('click', this.saveTemplate.bind(this));

			// Position grid in modal.
			$(document).on('click', '#wm-tpl-position-grid .wm-pos-btn', function () {
				$('#wm-tpl-position-grid .wm-pos-btn').removeClass('active');
				$(this).addClass('active');
				$('#wm-tpl-position').val($(this).data('pos'));
			});

			// Range slider displays in modal.
			$('#wm-tpl-opacity').on('input', function () {
				$('#wm-tpl-opacity-val').text($(this).val() + '%');
			});
			$('#wm-tpl-scale').on('input', function () {
				$('#wm-tpl-scale-val').text($(this).val() + '%');
			});
			$('#wm-tpl-rotation').on('input', function () {
				$('#wm-tpl-rotation-val').html($(this).val() + '&deg;');
			});

			// Tiling toggle.
			$('#wm-tpl-tiling').on('change', function () {
				$('.wm-tpl-tile-spacing-row').toggle($(this).is(':checked'));
			});

			// Init color picker in modal.
			if ($.fn.wpColorPicker) {
				$('.wm-tpl-color-picker').wpColorPicker();
			}
		},

		renderGrid: function () {
			var $grid = $('#wm-templates-grid');
			if (!$grid.length) {
				return;
			}

			$grid.empty();

			if (!this.templates.length) {
				$grid.append(
					'<div class="wm-no-templates">' +
						'<span class="wm-empty-icon dashicons dashicons-images-alt2"></span>' +
						'<span class="wm-empty-title">' + wmAdmin.strings.noTemplatesYet + '</span>' +
						'<span class="wm-empty-desc">' + wmAdmin.strings.noTemplatesDesc + '</span>' +
					'</div>'
				);
				return;
			}

			this.templates.forEach(function (tpl) {
				var $card = $('<div class="wm-template-card wm-fade-in">');

				// Preview canvas.
				var $preview = $('<div class="wm-template-card-preview">');
				var canvas = document.createElement('canvas');
				canvas.width = 280;
				canvas.height = 140;
				TemplateManager.renderCardPreview(canvas, tpl);
				$preview.append(canvas);
				$card.append($preview);

				// Body.
				var $body = $('<div class="wm-template-card-body">');
				$body.append($('<h4 class="wm-template-card-name">').text(tpl.name));

				var $meta = $('<div class="wm-template-card-meta">');
				$meta.append($('<span>').text(tpl.type));
				$meta.append($('<span>').text(tpl.position));
				$meta.append($('<span>').text(tpl.opacity + '%'));
				if (tpl.rotation) {
					$meta.append($('<span>').text(tpl.rotation + '\u00B0'));
				}
				if (tpl.tiling) {
					$meta.append($('<span>').text(wmAdmin.strings.tiled));
				}
				$body.append($meta);

				var $actions = $('<div class="wm-template-card-actions">');
				$actions.append(
					$('<button class="button wm-tpl-edit">').text(wmAdmin.strings.edit).data('id', tpl.id),
					$('<button class="button wm-tpl-delete">').text(wmAdmin.strings.delete).data('id', tpl.id)
				);
				$body.append($actions);

				$card.append($body);
				$grid.append($card);
			});
		},

		renderCardPreview: function (canvas, tpl) {
			var ctx = canvas.getContext('2d');
			var w = canvas.width;
			var h = canvas.height;

			// Background gradient.
			var gradient = ctx.createLinearGradient(0, 0, 0, h);
			gradient.addColorStop(0, '#87CEEB');
			gradient.addColorStop(0.6, '#4CAF50');
			gradient.addColorStop(1, '#2E7D32');
			ctx.fillStyle = gradient;
			ctx.fillRect(0, 0, w, h);

			if (tpl.type !== 'text') {
				return;
			}

			var text = tpl.text || tpl.name || 'Watermark';
			var opacity = (tpl.opacity || 50) / 100;
			var rotation = -(tpl.rotation || 0) * (Math.PI / 180);
			var fontSize = Math.max(8, Math.round((tpl.font_size || 24) * (w / 1000)));
			var color = tpl.font_color || '#ffffff';

			ctx.save();
			ctx.globalAlpha = opacity;
			ctx.font = fontSize + 'px sans-serif';
			ctx.fillStyle = color;

			if (tpl.tiling) {
				var metrics = ctx.measureText(text);
				var textW = metrics.width;
				var spacing = Math.round((tpl.tile_spacing || 100) * (w / 1000));
				var stepX = textW + spacing;
				var stepY = fontSize + spacing;

				for (var y = 0; y < h + w; y += stepY) {
					for (var x = -w; x < w * 2; x += stepX) {
						ctx.save();
						ctx.translate(x, y);
						ctx.rotate(rotation);
						ctx.fillText(text, 0, 0);
						ctx.restore();
					}
				}
			} else {
				var metrics2 = ctx.measureText(text);
				var pos = LivePreview.calcPosition(w, h, metrics2.width, fontSize, tpl.position, 10);
				ctx.translate(pos.x + metrics2.width / 2, pos.y + fontSize / 2);
				ctx.rotate(rotation);
				ctx.fillText(text, -metrics2.width / 2, fontSize / 2);
			}

			ctx.restore();
		},

		openNewModal: function () {
			$('#wm-tpl-id').val(0);
			$('#wm-template-modal-title').text(wmAdmin.strings.newTemplate);
			$('#wm-tpl-name').val('');
			$('#wm-tpl-type').val('text');
			$('#wm-tpl-text').val('');
			$('#wm-tpl-font-size').val(24);
			$('#wm-tpl-position').val('bottom-right');
			$('#wm-tpl-position-grid .wm-pos-btn').removeClass('active');
			$('#wm-tpl-position-grid .wm-pos-btn[data-pos="bottom-right"]').addClass('active');
			$('#wm-tpl-opacity').val(50);
			$('#wm-tpl-opacity-val').text('50%');
			$('#wm-tpl-scale').val(25);
			$('#wm-tpl-scale-val').text('25%');
			$('#wm-tpl-rotation').val(0);
			$('#wm-tpl-rotation-val').html('0&deg;');
			$('#wm-tpl-padding').val(20);
			$('#wm-tpl-tiling').prop('checked', false);
			$('#wm-tpl-tile-spacing').val(100);
			$('.wm-tpl-tile-spacing-row').hide();

			// Reset color picker.
			var $cp = $('#wm-tpl-font-color');
			if ($cp.closest('.wp-picker-container').length) {
				$cp.wpColorPicker('color', '#ffffff');
			} else {
				$cp.val('#ffffff');
			}

			$('#wm-template-modal').show();
		},

		openEditModal: function () {
			var id = $(this).data('id');
			var tpl = TemplateManager.templates.find(function (t) { return t.id === id; });
			if (!tpl) {
				return;
			}

			// Show breadcrumb for edit mode.
			$('.wm-template-breadcrumb').show();

			$('#wm-tpl-id').val(tpl.id);
			$('#wm-template-modal-title').text(wmAdmin.strings.editTemplate);
			$('#wm-tpl-name').val(tpl.name);
			$('#wm-tpl-type').val(tpl.type);
			$('#wm-tpl-text').val(tpl.text);
			$('#wm-tpl-font-size').val(tpl.font_size);
			$('#wm-tpl-position').val(tpl.position);
			$('#wm-tpl-position-grid .wm-pos-btn').removeClass('active');
			$('#wm-tpl-position-grid .wm-pos-btn[data-pos="' + tpl.position + '"]').addClass('active');
			$('#wm-tpl-opacity').val(tpl.opacity);
			$('#wm-tpl-opacity-val').text(tpl.opacity + '%');
			$('#wm-tpl-scale').val(tpl.scale);
			$('#wm-tpl-scale-val').text(tpl.scale + '%');
			$('#wm-tpl-rotation').val(tpl.rotation);
			$('#wm-tpl-rotation-val').html(tpl.rotation + '&deg;');
			$('#wm-tpl-padding').val(tpl.padding);
			$('#wm-tpl-tiling').prop('checked', tpl.tiling);
			$('#wm-tpl-tile-spacing').val(tpl.tile_spacing);
			$('.wm-tpl-tile-spacing-row').toggle(!!tpl.tiling);

			var $cp = $('#wm-tpl-font-color');
			if ($cp.closest('.wp-picker-container').length) {
				$cp.wpColorPicker('color', tpl.font_color || '#ffffff');
			} else {
				$cp.val(tpl.font_color || '#ffffff');
			}

			$('#wm-template-modal').show();
		},

		closeModal: function () {
			$('#wm-template-modal').hide();
			$('.wm-template-breadcrumb').hide();
		},

		saveTemplate: function () {
			var data = {
				action: 'wm_save_template',
				nonce: wmAdmin.templateNonce,
				template_id: $('#wm-tpl-id').val(),
				name: $('#wm-tpl-name').val(),
				type: $('#wm-tpl-type').val(),
				text: $('#wm-tpl-text').val(),
				font_size: $('#wm-tpl-font-size').val(),
				font_color: $('#wm-tpl-font-color').val(),
				position: $('#wm-tpl-position').val(),
				opacity: $('#wm-tpl-opacity').val(),
				scale: $('#wm-tpl-scale').val(),
				rotation: $('#wm-tpl-rotation').val(),
				padding: $('#wm-tpl-padding').val(),
				tiling: $('#wm-tpl-tiling').is(':checked') ? 1 : 0,
				tile_spacing: $('#wm-tpl-tile-spacing').val(),
			};

			var $btn = $('#wm-tpl-save');
			ButtonLoading.start($btn);

			$.post(wmAdmin.ajaxUrl, data)
				.done(function (response) {
					if (response.success) {
						// Refresh template list.
						TemplateManager.refreshTemplates();
						TemplateManager.closeModal();
						Toast.success(wmAdmin.strings.templateSaved);
					} else {
						Toast.error(response.data || wmAdmin.strings.error);
					}
				})
				.fail(function () {
					Toast.error(wmAdmin.strings.error);
				})
				.always(function () {
					ButtonLoading.stop($btn);
				});
		},

		deleteTemplate: function () {
			var id = $(this).data('id');
			var $btn = $(this);

			ConfirmDialog.show(wmAdmin.strings.confirmDelete, function () {
				ButtonLoading.start($btn);

				$.post(wmAdmin.ajaxUrl, {
					action: 'wm_delete_template',
					nonce: wmAdmin.templateNonce,
					template_id: id,
				}).done(function (response) {
					if (response.success) {
						TemplateManager.refreshTemplates();
						Toast.success(wmAdmin.strings.templateDeleted);
					} else {
						Toast.error(response.data || wmAdmin.strings.error);
					}
				}).always(function () {
					ButtonLoading.stop($btn);
				});
			});
		},

		refreshTemplates: function () {
			$.post(wmAdmin.ajaxUrl, {
				action: 'wm_get_templates',
				nonce: wmAdmin.templateNonce,
			}).done(function (response) {
				if (response.success) {
					TemplateManager.templates = response.data;
					TemplateManager.renderGrid();
				}
			});
		},
	};

	/**
	 * Backup Browser module.
	 */
	const BackupBrowser = {
		currentPage: 1,

		init: function () {
			this.updateStats();
			this.loadPage(1);

			$('#wm-run-cleanup').on('click', this.runCleanup.bind(this));
			$('#wm-refresh-backups').on('click', function () {
				var $btn = $(this);
				ButtonLoading.start($btn);
				BackupBrowser.updateStats();
				BackupBrowser.loadPage(1);
				setTimeout(function () {
					ButtonLoading.stop($btn);
				}, 800);
			});

			$(document).on('click', '.wm-backup-restore', this.restoreBackup.bind(this));
			$(document).on('click', '.wm-backup-delete', this.deleteBackup.bind(this));
			$(document).on('click', '.wm-page-btn', function () {
				BackupBrowser.loadPage(parseInt($(this).data('page'), 10));
			});
		},

		updateStats: function () {
			if (!$('#wm-backup-stats').length) {
				return;
			}

			$.post(wmAdmin.ajaxUrl, {
				action: 'wm_backup_status',
				nonce: wmAdmin.backupNonce,
			}).done(function (response) {
				if (!response.success) {
					return;
				}

				var s = response.data;
				$('#wm-backup-count').text(s.total_backups);
				$('#wm-backup-size').text(s.total_size_human);
				$('#wm-backup-usage').text(s.disk_usage_pct + '%');

				if (s.disk_usage_pct >= s.warn_threshold) {
					$('#wm-backup-warning').show();
				} else {
					$('#wm-backup-warning').hide();
				}
			});
		},

		loadPage: function (page) {
			if (!$('#wm-backup-list').length) {
				return;
			}

			this.currentPage = page;

			$.post(wmAdmin.ajaxUrl, {
				action: 'wm_backup_list',
				nonce: wmAdmin.backupNonce,
				page: page,
			}).done(function (response) {
				if (!response.success) {
					return;
				}

				var data = response.data;
				var $tbody = $('#wm-backup-list').empty();

				if (!data.items.length) {
					$tbody.append(
						$('<tr>').append(
							$('<td colspan="5">').html(
								'<div class="wm-empty-state" style="padding: 32px 20px;">' +
									'<div class="wm-empty-illustration" style="width: 64px; height: 64px; margin: 0 auto 12px;">' +
										'<span class="dashicons dashicons-portfolio" style="font-size: 28px; width: 28px; height: 28px;"></span>' +
									'</div>' +
									'<h3 style="font-size: 14px;">' + wmAdmin.strings.noBackupsFound + '</h3>' +
									'<p style="font-size: 12px; margin-bottom: 0;">' + wmAdmin.strings.noBackupsDesc + '</p>' +
								'</div>'
							)
						)
					);
					$('#wm-backup-pagination').empty();
					return;
				}

				data.items.forEach(function (item) {
					var $tr = $('<tr class="wm-fade-in">');

					// Thumbnail.
					var $thumb = $('<td>');
					if (item.thumbnail) {
						$thumb.append($('<img class="wm-backup-thumb">').attr('src', item.thumbnail));
					}
					$tr.append($thumb);

					$tr.append($('<td>').text(item.title));
					$tr.append($('<td>').text(item.backup_date || '-'));
					$tr.append($('<td>').text(item.backup_size));

					// Actions.
					var $actions = $('<td>');
					if (item.backup_exists) {
						$actions.append(
							$('<button class="button button-small wm-backup-restore">')
								.text(wmAdmin.strings.restore)
								.data('id', item.attachment_id),
							' ',
							$('<button class="button button-small wm-backup-delete">')
								.text(wmAdmin.strings.delete)
								.data('id', item.attachment_id)
						);
					} else {
						$actions.text(wmAdmin.strings.backupFileMissing);
					}
					$tr.append($actions);

					$tbody.append($tr);
				});

				// Pagination.
				var $pag = $('#wm-backup-pagination').empty();
				if (data.pages > 1) {
					for (var p = 1; p <= data.pages; p++) {
						$pag.append(
							$('<button class="wm-page-btn">')
								.addClass(p === page ? 'active' : '')
								.text(p)
								.data('page', p)
						);
					}
				}
			});
		},

		restoreBackup: function () {
			var id = $(this).data('id');
			var $btn = $(this);

			ConfirmDialog.show(wmAdmin.strings.confirmRestore, function () {
				ButtonLoading.start($btn);

				$.post(wmAdmin.ajaxUrl, {
					action: 'wm_restore_image',
					nonce: wmAdmin.backupNonce,
					attachment_id: id,
				}).done(function (response) {
					if (response.success) {
						Toast.success(wmAdmin.strings.imageRestored);
						BackupBrowser.updateStats();
						BackupBrowser.loadPage(BackupBrowser.currentPage);
					} else {
						Toast.error(response.data || wmAdmin.strings.error);
					}
				}).always(function () {
					ButtonLoading.stop($btn);
				});
			});
		},

		deleteBackup: function () {
			var id = $(this).data('id');
			var $btn = $(this);

			ConfirmDialog.show(wmAdmin.strings.confirmDeleteBackup, function () {
				ButtonLoading.start($btn);

				$.post(wmAdmin.ajaxUrl, {
					action: 'wm_delete_backup',
					nonce: wmAdmin.backupNonce,
					attachment_id: id,
				}).done(function (response) {
					if (response.success) {
						Toast.success(wmAdmin.strings.backupDeleted);
						BackupBrowser.updateStats();
						BackupBrowser.loadPage(BackupBrowser.currentPage);
					} else {
						Toast.error(response.data || wmAdmin.strings.error);
					}
				}).always(function () {
					ButtonLoading.stop($btn);
				});
			});
		},

		runCleanup: function () {
			var $btn = $('#wm-run-cleanup');

			ConfirmDialog.show(wmAdmin.strings.confirmCleanup, function () {
				ButtonLoading.start($btn);

				$.post(wmAdmin.ajaxUrl, {
					action: 'wm_cleanup_backups',
					nonce: wmAdmin.backupNonce,
				}).done(function (response) {
					if (response.success) {
						Toast.success(response.data.message);
						BackupBrowser.updateStats();
						BackupBrowser.loadPage(1);
					}
				}).always(function () {
					ButtonLoading.stop($btn);
				});
			});
		},
	};

	/**
	 * Activity Log module.
	 */
	const ActivityLog = {
		init: function () {
			this.load();
		},

		load: function () {
			if (!$('#wm-activity-log-body').length) {
				return;
			}

			$.post(wmAdmin.ajaxUrl, {
				action: 'wm_get_activity_log',
				nonce: wmAdmin.settingsNonce,
			}).done(function (response) {
				if (!response.success) {
					return;
				}

				var $tbody = $('#wm-activity-log-body').empty();
				var log = response.data;

				if (!log.length) {
					$tbody.append(
						$('<tr>').append(
							$('<td colspan="5">').html(
								'<div class="wm-empty-state" style="padding: 32px 20px;">' +
									'<div class="wm-empty-illustration" style="width: 64px; height: 64px; margin: 0 auto 12px;">' +
										'<span class="dashicons dashicons-list-view" style="font-size: 28px; width: 28px; height: 28px;"></span>' +
									'</div>' +
									'<h3 style="font-size: 14px;">' + wmAdmin.strings.noActivityYet + '</h3>' +
									'<p style="font-size: 12px; margin-bottom: 0;">' + wmAdmin.strings.noActivityDesc + '</p>' +
								'</div>'
							)
						)
					);
					return;
				}

				log.forEach(function (entry) {
					var $tr = $('<tr class="wm-fade-in">');
					$tr.append($('<td>').text(entry.timestamp));
					$tr.append(
						$('<td>').append(
							$('<span class="wm-activity-action wm-action-' + entry.action + '">')
								.text(entry.action.replace(/_/g, ' '))
						)
					);
					$tr.append(
						$('<td>').text(
							entry.attachment_id > 0 ? '#' + entry.attachment_id : '-'
						)
					);
					$tr.append($('<td>').text(entry.details || '-'));
					$tr.append(
						$('<td>').text(
							entry.user_id > 0 ? 'User #' + entry.user_id : wmAdmin.strings.system
						)
					);
					$tbody.append($tr);
				});
			});
		},
	};

	/**
	 * Import/Export module.
	 */
	const ImportExport = {
		init: function () {
			$('#wm-export-settings').on('click', this.exportSettings);
			$('#wm-import-trigger').on('click', function () {
				$('#wm-import-file').click();
			});
			$('#wm-import-file').on('change', this.importSettings);
		},

		exportSettings: function () {
			var $btn = $(this);
			ButtonLoading.start($btn);

			$.post(wmAdmin.ajaxUrl, {
				action: 'wm_export_settings',
				nonce: wmAdmin.settingsNonce,
			}).done(function (response) {
				if (!response.success) {
					return;
				}

				var json = JSON.stringify(response.data, null, 2);
				var blob = new Blob([json], { type: 'application/json' });
				var url = URL.createObjectURL(blob);
				var a = document.createElement('a');
				a.href = url;
				a.download = 'watermark-manager-settings.json';
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
				URL.revokeObjectURL(url);
				Toast.success(wmAdmin.strings.exportSuccess);
			}).always(function () {
				ButtonLoading.stop($btn);
			});
		},

		importSettings: function () {
			var file = this.files[0];
			if (!file) {
				return;
			}

			var reader = new FileReader();
			reader.onload = function (e) {
				try {
					JSON.parse(e.target.result);
				} catch (err) {
					Toast.error(wmAdmin.strings.invalidJson);
					return;
				}

				var $btn = $('#wm-import-trigger');
				ButtonLoading.start($btn);

				$.post(wmAdmin.ajaxUrl, {
					action: 'wm_import_settings',
					nonce: wmAdmin.settingsNonce,
					import_data: e.target.result,
				}).done(function (response) {
					if (response.success) {
						Toast.success(wmAdmin.strings.importSuccess);
						setTimeout(function () {
							window.location.reload();
						}, 1500);
					} else {
						Toast.error(response.data || wmAdmin.strings.error);
					}
				}).always(function () {
					ButtonLoading.stop($btn);
				});
			};
			reader.readAsText(file);
		},
	};

	/**
	 * Media selector for watermark image field.
	 */
	const ImageSelector = {
		frame: null,

		init: function () {
			$('#wm-select-image').on('click', this.openFrame.bind(this));
			$('#wm-remove-image').on('click', this.removeImage.bind(this));
		},

		openFrame: function (e) {
			e.preventDefault();

			if (this.frame) {
				this.frame.open();
				return;
			}

			this.frame = wp.media({
				title: wmAdmin.strings.selectImage,
				button: { text: wmAdmin.strings.useImage },
				multiple: false,
				library: { type: 'image' },
			});

			this.frame.on('select', function () {
				var attachment = ImageSelector.frame
					.state()
					.get('selection')
					.first()
					.toJSON();

				$('#wm-watermark-image-id').val(attachment.id);
				$('#wm-watermark-preview').html(
					$('<img>').attr('src', attachment.sizes.thumbnail
						? attachment.sizes.thumbnail.url
						: attachment.url
					)
				);
				$('#wm-remove-image').show();
			});

			this.frame.open();
		},

		removeImage: function (e) {
			e.preventDefault();
			$('#wm-watermark-image-id').val('0');
			$('#wm-watermark-preview').empty();
			$('#wm-remove-image').hide();
		},
	};

	/**
	 * Range slider live value displays, position grid, and keyboard shortcuts.
	 */
	const UIControls = {
		init: function () {
			$('#wm-scale-range').on('input', function () {
				$('#wm-scale-value').text($(this).val() + '%');
			});

			$('#wm-opacity-range').on('input', function () {
				$('#wm-opacity-value').text($(this).val() + '%');
			});

			$('#wm-rotation-range').on('input', function () {
				$('#wm-rotation-value').html($(this).val() + '&deg;');
			});

			$('#wm-jpeg-quality-range').on('input', function () {
				$('#wm-jpeg-quality-value').text($(this).val() + '%');
			});

			// Position grid (drag-and-drop style selector).
			$(document).on('click', '#wm-position-grid .wm-pos-btn', function () {
				$('#wm-position-grid .wm-pos-btn').removeClass('active');
				$(this).addClass('active');
				$('#wm-position-input').val($(this).data('pos')).trigger('change');
			});

			// Smooth scroll for tab switching.
			$('.wm-nav-tabs .nav-tab').on('click', function () {
				var $tabContent = $('.wm-tab-content');
				if ($tabContent.length) {
					setTimeout(function () {
						$tabContent[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
					}, 100);
				}
			});

			// Add fade-in to tab content on load.
			$('.wm-tab-content').addClass('wm-fade-in');

			// Keyboard shortcuts.
			this.initKeyboardShortcuts();
		},

		initKeyboardShortcuts: function () {
			$(document).on('keydown', function (e) {
				// Escape to close modals.
				if (e.key === 'Escape') {
					var $modal = $('.wm-modal:visible');
					if ($modal.length && !$modal.hasClass('wm-confirm-modal')) {
						$modal.hide();
						$('.wm-template-breadcrumb').hide();
						e.preventDefault();
					}
				}

				// Ctrl/Cmd+S to save settings.
				if ((e.ctrlKey || e.metaKey) && e.key === 's') {
					var $settingsForm = $('#wm-settings-form');
					if ($settingsForm.length && $settingsForm.is(':visible')) {
						e.preventDefault();
						$settingsForm.find('input[type="submit"]').click();
						Toast.info(wmAdmin.strings.savingSettings);
					}

					// Save template if modal is open.
					var $templateModal = $('#wm-template-modal');
					if ($templateModal.length && $templateModal.is(':visible')) {
						e.preventDefault();
						$('#wm-tpl-save').click();
					}
				}
			});
		},
	};

	/**
	 * Single attachment watermark controls (meta box).
	 */
	const SingleActions = {
		init: function () {
			$(document).on('click', '.wm-apply-single', this.apply);
			$(document).on('click', '.wm-remove-single', this.remove);
		},

		apply: function () {
			var $box = $(this).closest('.wm-meta-box');
			var attachmentId = $box.data('attachment-id');
			var nonce = $box.data('nonce');
			var $btn = $(this);

			ButtonLoading.start($btn);
			SingleActions.setLoading($box, true);

			$.post(wmAdmin.ajaxUrl, {
				action: 'wm_apply_single',
				nonce: nonce,
				attachment_id: attachmentId,
			})
				.done(function (response) {
					SingleActions.showMessage(
						$box,
						response.success ? response.data : (response.data || wmAdmin.strings.error),
						response.success ? 'success' : 'error'
					);

					if (response.success) {
						// Show toast with undo option.
						Toast.success(wmAdmin.strings.watermarkApplied, 8000, {
							undoCallback: function () {
								$.post(wmAdmin.ajaxUrl, {
									action: 'wm_restore_image',
									nonce: wmAdmin.backupNonce || nonce,
									attachment_id: attachmentId,
								}).done(function (undoResp) {
									if (undoResp && undoResp.success) {
										Toast.success(wmAdmin.strings.watermarkRemoved);
										setTimeout(function () {
											window.location.reload();
										}, 1000);
									} else {
										Toast.error(wmAdmin.strings.undoFailed);
									}
								});
							},
						});

						setTimeout(function () {
							window.location.reload();
						}, 2000);
					}
				})
				.fail(function () {
					SingleActions.showMessage($box, wmAdmin.strings.error, 'error');
				})
				.always(function () {
					ButtonLoading.stop($btn);
					SingleActions.setLoading($box, false);
				});
		},

		remove: function () {
			var $box = $(this).closest('.wm-meta-box');
			var attachmentId = $box.data('attachment-id');
			var nonce = $box.data('nonce');
			var $btn = $(this);

			ButtonLoading.start($btn);
			SingleActions.setLoading($box, true);

			$.post(wmAdmin.ajaxUrl, {
				action: 'wm_remove_single',
				nonce: nonce,
				attachment_id: attachmentId,
			})
				.done(function (response) {
					SingleActions.showMessage(
						$box,
						response.success ? response.data : (response.data || wmAdmin.strings.error),
						response.success ? 'success' : 'error'
					);

					if (response.success) {
						setTimeout(function () {
							window.location.reload();
						}, 1000);
					}
				})
				.fail(function () {
					SingleActions.showMessage($box, wmAdmin.strings.error, 'error');
				})
				.always(function () {
					ButtonLoading.stop($btn);
					SingleActions.setLoading($box, false);
				});
		},

		setLoading: function ($box, loading) {
			$box.find('.button').prop('disabled', loading);
		},

		showMessage: function ($box, text, type) {
			var $msg = $box.find('.wm-meta-message');
			$msg.removeClass('wm-message-success wm-message-error')
				.addClass('wm-message-' + type)
				.text(text)
				.show();
		},
	};

	/**
	 * Color picker init.
	 */
	const ColorPicker = {
		init: function () {
			if ($.fn.wpColorPicker) {
				$('.wm-color-picker').wpColorPicker({
					change: function () {
						// Trigger preview update.
						setTimeout(function () {
							$('.wm-setting-input').first().trigger('change');
						}, 50);
					},
				});
			}
		},
	};

	/**
	 * Boot everything on DOM ready.
	 */
	$(function () {
		Toast.init();
		LivePreview.init();
		BatchProcessor.init();
		TemplateManager.init();
		BackupBrowser.init();
		ActivityLog.init();
		ImportExport.init();
		ImageSelector.init();
		UIControls.init();
		SingleActions.init();
		ColorPicker.init();
	});
})(jQuery);
