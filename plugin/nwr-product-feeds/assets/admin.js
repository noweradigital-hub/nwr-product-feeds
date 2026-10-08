/* Produktové feedy — admin. No dependencies. */
( function () {
	'use strict';

	var cfg = window.nwrPf || {};
	var i18n = cfg.i18n || {};

	function ajax( params ) {
		var query = new URLSearchParams( params );
		query.set( '_ajax_nonce', cfg.nonce || '' );
		return fetch( cfg.ajax + '?' + query.toString(), { credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} );
	}

	// Copy buttons.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-copy]' );
		if ( ! btn ) {
			return;
		}
		var text = btn.getAttribute( 'data-copy' );
		var done = function () {
			var old = btn.textContent;
			btn.textContent = i18n.copied || 'OK';
			setTimeout( function () {
				btn.textContent = old;
			}, 1500 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			var input = btn.parentNode.querySelector( 'input' );
			if ( input ) {
				input.select();
				document.execCommand( 'copy' );
				done();
			}
		}
	} );

	// Confirmations.
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( '[data-confirm]' );
		if ( link && ! window.confirm( link.getAttribute( 'data-confirm' ) || i18n.confirm ) ) {
			e.preventDefault();
		}
	} );

	// Reload once a running generation has finished.
	if ( document.querySelector( '[data-nwr-pf-poll]' ) ) {
		var poll = function () {
			ajax( { action: 'nwr_pf_status' } ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					return;
				}
				var running = Object.keys( res.data ).some( function ( id ) {
					return 'running' === res.data[ id ].status;
				} );
				if ( running ) {
					setTimeout( poll, 4000 );
				} else {
					window.location.reload();
				}
			} ).catch( function () {
				setTimeout( poll, 10000 );
			} );
		};
		setTimeout( poll, 3000 );
	}

	// Google category picker (also inside variations loaded later).
	var timer = null;

	function listOf( picker ) {
		return picker.querySelector( '.nwr-pf-gpc-list' );
	}

	function close( picker ) {
		var list = listOf( picker );
		list.hidden = true;
		list.innerHTML = '';
	}

	function choose( picker, id, label ) {
		var hidden = picker.querySelector( 'input[type=hidden]' );
		var field = picker.querySelector( '.nwr-pf-gpc-q' );
		hidden.value = id;
		field.value = label;
		hidden.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		close( picker );
	}

	function render( picker, items, message ) {
		var list = listOf( picker );
		list.innerHTML = '';
		if ( message ) {
			var note = document.createElement( 'span' );
			note.className = 'nwr-pf-gpc-note';
			note.textContent = message;
			list.appendChild( note );
		}
		( items || [] ).forEach( function ( item, index ) {
			var option = document.createElement( 'button' );
			option.type = 'button';
			option.className = 'nwr-pf-gpc-option' + ( 0 === index ? ' is-active' : '' );
			option.textContent = item.label;
			option.setAttribute( 'data-id', item.id );
			list.appendChild( option );
		} );
		list.hidden = false;
	}

	function search( picker ) {
		var field = picker.querySelector( '.nwr-pf-gpc-q' );
		var query = field.value.trim();
		if ( '' === query ) {
			choose( picker, '', '' );
			return;
		}
		if ( query.length < 2 && ! /^\d+$/.test( query ) ) {
			return;
		}
		render( picker, [], i18n.loading );
		ajax( { action: 'nwr_pf_gpc_search', q: query } ).then( function ( res ) {
			if ( field.value.trim() !== query ) {
				return;
			}
			if ( ! res || ! res.success ) {
				render( picker, [], ( res && res.data && res.data.message ) || i18n.error );
				return;
			}
			render( picker, res.data, res.data.length ? '' : i18n.none );
		} ).catch( function () {
			render( picker, [], i18n.error );
		} );
	}

	document.addEventListener( 'input', function ( e ) {
		if ( ! e.target.classList || ! e.target.classList.contains( 'nwr-pf-gpc-q' ) ) {
			return;
		}
		var picker = e.target.closest( '.nwr-pf-gpc' );
		clearTimeout( timer );
		timer = setTimeout( function () {
			search( picker );
		}, 250 );
	} );

	document.addEventListener( 'click', function ( e ) {
		var option = e.target.closest( '.nwr-pf-gpc-option' );
		if ( option ) {
			e.preventDefault();
			choose( option.closest( '.nwr-pf-gpc' ), option.getAttribute( 'data-id' ), option.textContent );
			return;
		}
		document.querySelectorAll( '.nwr-pf-gpc' ).forEach( function ( picker ) {
			if ( ! picker.contains( e.target ) ) {
				close( picker );
			}
		} );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( ! e.target.classList || ! e.target.classList.contains( 'nwr-pf-gpc-q' ) ) {
			return;
		}
		var picker = e.target.closest( '.nwr-pf-gpc' );
		var list = listOf( picker );
		var options = Array.prototype.slice.call( list.querySelectorAll( '.nwr-pf-gpc-option' ) );
		var active = list.querySelector( '.is-active' );
		var index = options.indexOf( active );

		if ( 'Escape' === e.key ) {
			close( picker );
		} else if ( ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) && options.length ) {
			e.preventDefault();
			index = ( index + ( 'ArrowDown' === e.key ? 1 : -1 ) + options.length ) % options.length;
			options.forEach( function ( option, i ) {
				option.classList.toggle( 'is-active', i === index );
			} );
			options[ index ].scrollIntoView( { block: 'nearest' } );
		} else if ( 'Enter' === e.key ) {
			// Never submit the product or feed form from the search field.
			e.preventDefault();
			if ( active ) {
				choose( picker, active.getAttribute( 'data-id' ), active.textContent );
			}
		}
	} );
} )();
