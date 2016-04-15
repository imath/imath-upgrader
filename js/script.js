window.wp = window.wp || {};
window.imathUpgrader = window.imathUpgrader || {};

( function( exports, $ ) {

	if ( typeof Imath_Upgrader === 'undefined' ) {
		return;
	}

	_.extend( imathUpgrader, _.pick( wp, 'Backbone', 'ajax', 'template' ) );

	// Init Models and Collections
	imathUpgrader.Models      = imathUpgrader.Models || {};
	imathUpgrader.Collections = imathUpgrader.Collections || {};

	// Init Views
	imathUpgrader.Views = imathUpgrader.Views || {};

	/**
	 * The Upgrader!
	 */
	imathUpgrader.Tool = {
		/**
		 * Launcher
		 */
		start: function() {
			this.tasks = new imathUpgrader.Collections.Tasks();
			this.completed = false;

			// Create the task list view
			var task_list = new imathUpgrader.Views.Upgrader( { collection: this.tasks } );

			task_list.inject( '#imath-upgrader' );

			this.setUpTasks();
		},

		/**
		 * Populate the tasks collection
		 */
		setUpTasks: function() {
			var self = this;

			_.each( Imath_Upgrader.tasks, function( task, index ) {
				if ( ! _.isObject( task ) ) {
					return;
				}

				self.tasks.add( {
					id      : task.callback,
					order   : index,
					message : task.message,
					count   : task.count,
					number  : task.number,
					done    : 0,
					active  : false
				} );
			} );
		}
	}

	/**
	 * The Tasks collection
	 */
	imathUpgrader.Collections.Tasks = Backbone.Collection.extend( {
		proceed: function( options ) {
			options         = options || {};
			options.context = this;
			options.data    = options.data || {};

			options.data = _.extend( options.data, {
				action                  : 'imath_upgrader',
				'_imath_upgrader_nonce' : Imath_Upgrader.nonce
			} );

			return imathUpgrader.ajax.send( options );
		},
	} );

	/**
	 * Extend Backbone.View with .prepare() and .inject()
	 */
	imathUpgrader.View = imathUpgrader.Backbone.View.extend( {
		inject: function( selector ) {
			this.render();
			$( selector ).html( this.el );
			this.views.ready();
		},

		prepare: function() {
			if ( ! _.isUndefined( this.model ) && _.isFunction( this.model.toJSON ) ) {
				return this.model.toJSON();
			} else {
				return {};
			}
		}
	} );

	/**
	 * List of tasks view
	 */
	imathUpgrader.Views.Upgrader = imathUpgrader.View.extend( {
		tagName   : 'div',

		initialize: function() {
			this.views.add( new imathUpgrader.View( { tagName: 'ul', id: 'imath-upgrader-tasks' } ) );

			this.collection.on( 'add', this.injectTask, this );
			this.collection.on( 'change:active', this.manageQueue, this );
			this.collection.on( 'change:done', this.manageQueue, this );
		},

		taskSuccess: function( response ) {
			var task, next, nextTask;

			if ( response.done && response.callback ) {
				task = this.get( response.callback );

				task.set( 'done', Number( response.done ) + Number( task.get( 'done' ) ) );

				if ( Number( task.get( 'count' ) ) === Number( task.get( 'done' ) ) ) {
					task.set( 'active', false );

					next     = Number( task.get( 'order' ) ) + 1;
					nextTask = this.findWhere( { order: next } );

					if ( _.isObject( nextTask ) ) {
						nextTask.set( 'active', true );
					} else {
						$( '.dashboard_page_imath-upgrader #message' ).removeClass( 'imath-upgrader-hide' );
					}
				}
			}
		},

		taskError: function( response ) {
			if ( response.message && response.callback ) {
				if ( 'warning' === response.type ) {
					var task = this.get( response.callback );
					response.message = response.message.replace( '%d', Number( task.get( 'count' ) ) - Number( task.get( 'done' ) ) );
				}

				$( '#' + response.callback + ' .imath-upgrader-progress' ).html( response.message ).addClass( response.type );
			} else {
				$( '.dashboard_page_imath-upgrader #message' ).html( '<p>' + response.message + '</p>' ).removeClass( 'imath-upgrader-hide updated' ).addClass( 'error' );
			}
		},

		injectTask: function( task ) {
			this.views.add( '#imath-upgrader-tasks', new imathUpgrader.Views.Task( { model: task } ) );
		},

		manageQueue: function( task ) {
			if ( true === task.get( 'active' ) ) {
				this.collection.proceed( {
					data    : _.pick( task.attributes, ['id', 'count', 'number', 'done'] ),
					success : this.taskSuccess,
					error   : this.taskError
				} );
			}
		}
	} );

	/**
	 * The task view
	 */
	imathUpgrader.Views.Task = imathUpgrader.View.extend( {
		tagName   : 'li',
		template  : imathUpgrader.template( 'progress-window' ),
		className : 'imath-upgrader-task',

		initialize: function() {
			this.model.on( 'change:done', this.taskProgress, this );
			this.model.on( 'change:active', this.addClass, this );

			if ( 0 === this.model.get( 'order' ) ) {
				this.model.set( 'active', true );
			}
		},

		addClass: function( task ) {
			if ( true === task.get( 'active' ) ) {
				$( this.$el ).addClass( 'active' );
			}
		},

		taskProgress: function( task ) {
			if ( ! _.isUndefined( task.get( 'done' ) ) && ! _.isUndefined( task.get( 'count' ) ) ) {
				var percent = ( Number( task.get( 'done' ) ) / Number( task.get( 'count' ) ) ) * 100;
				$( '#' + task.get( 'id' ) + ' .imath-upgrader-progress .imath-upgrader-bar' ).css( 'width', percent + '%' );
			}
		}
	} );

	imathUpgrader.Tool.start();

} )( imathUpgrader, jQuery );
