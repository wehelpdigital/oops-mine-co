/**
 * The live mixer: microphone, background music and sound pads, mixed into one audio track.
 *
 * Like the canvas, that track is what goes to Daily, and it stays the same track for the whole stream
 * while everything feeding it changes. This is the reason muting is a gain here and not Daily's
 * setLocalAudio: muting the host must not silence the music, and changing microphone must not touch
 * the call.
 *
 * Music and pads also play through the host's own speakers (`monitor`), because a host who presses a
 * pad and hears nothing thinks it is broken. They can turn that off. Their microphone never plays back.
 *
 * A few pads are synthesised here. The rest, and the ready-made music, are public-domain recordings in
 * assets/live-sounds (see CREDITS.md there), played through the same buses.
 *
 * @package whd
 */
( function ( root ) {
	'use strict';

	var AudioCtx = window.AudioContext || window.webkitAudioContext;

	var PADS = {
		ding: { label: 'Ding', icon: '🔔' },
		sold: { label: 'Cha-ching', icon: '💰' },
		tada: { label: 'Ta-da', icon: '🎉' }
	};

	function Mixer() {
		var ctx = this.ctx = new AudioCtx( { latencyHint: 'interactive' } );

		this.out = ctx.createMediaStreamDestination();
		this.master = ctx.createGain();

		// A limiter of sorts: a pad on top of music on top of a voice must not clip for the audience.
		this.limiter = ctx.createDynamicsCompressor();
		this.limiter.threshold.value = -6;
		this.limiter.ratio.value = 12;
		this.limiter.attack.value = 0.003;
		this.limiter.release.value = 0.2;

		this.master.connect( this.limiter );
		this.limiter.connect( this.out );

		// What the host can choose to hear: music and pads, never their own microphone.
		this.monitor = ctx.createGain();
		this.monitor.gain.value = 1;
		this.monitor.connect( ctx.destination );
		this.hearing = true;
		this.onError = null;

		this.buses = {};
		this.levels = {};
		var self = this;
		[ 'mic', 'music', 'pads', 'screen' ].forEach( function ( name ) {
			var gain = ctx.createGain();
			var meter = ctx.createAnalyser();
			meter.fftSize = 512;
			meter.smoothingTimeConstant = 0.6;
			gain.connect( self.master );
			if ( 'mic' !== name ) {
				gain.connect( self.monitor );
			}
			self.buses[ name ] = gain;
			self.levels[ name ] = { meter: meter, data: new Uint8Array( meter.fftSize ) };
		} );
		// Music and pads are measured after the fader, the microphone before it (see setMic).
		this.buses.music.connect( this.levels.music.meter );
		this.buses.pads.connect( this.levels.pads.meter );
		this.buses.screen.connect( this.levels.screen.meter );

		this.volumes = { mic: 1, music: 0.35, pads: 0.8, screen: 0.8 };
		this.muted = false;
		this.duck = true;
		this._ducked = false;
		this._apply();

		this._micStream = null;
		this._micNode = null;
		this._screenNode = null;
		this.padBuffers = {};

		this.track = this.out.stream.getAudioTracks()[ 0 ];

		/* Music */
		this.audio = new Audio();
		this.audio.crossOrigin = 'anonymous';
		this.audio.preload = 'auto';
		this.musicNode = ctx.createMediaElementSource( this.audio );
		this.musicNode.connect( this.buses.music );
		this.playlist = [];
		this.index = 0;
		this.loop = true;
		this.onMusic = null;
		this.audio.addEventListener( 'ended', function () {
			self.next( true );
		} );
		this.audio.addEventListener( 'error', function () {
			if ( self.onError ) {
				self.onError();
			}
			if ( self.onMusic ) {
				self.onMusic();
			}
		} );
		[ 'play', 'pause', 'loadedmetadata' ].forEach( function ( type ) {
			self.audio.addEventListener( type, function () {
				if ( self.onMusic ) {
					self.onMusic();
				}
			} );
		} );
	}

	/* Browsers start the audio engine suspended until somebody has clicked. */
	Mixer.prototype.wake = function () {
		if ( 'suspended' === this.ctx.state ) {
			this.ctx.resume();
		}
	};

	Mixer.prototype._apply = function () {
		var now = this.ctx.currentTime;
		var set = function ( param, value ) {
			param.setTargetAtTime( value, now, 0.03 );
		};
		set( this.buses.mic.gain, this.muted ? 0 : this.volumes.mic );
		set( this.buses.music.gain, this.volumes.music * ( this._ducked ? 0.3 : 1 ) );
		set( this.buses.pads.gain, this.volumes.pads );
		set( this.buses.screen.gain, this.volumes.screen );
	};

	Mixer.prototype.setVolume = function ( bus, value ) {
		this.volumes[ bus ] = Math.max( 0, Math.min( 1.5, +value || 0 ) );
		this._apply();
	};

	Mixer.prototype.setMuted = function ( on ) {
		this.muted = !! on;
		this._apply();
	};

	Mixer.prototype.setMonitor = function ( on ) {
		this.hearing = !! on;
		this.monitor.gain.setTargetAtTime( on ? 1 : 0, this.ctx.currentTime, 0.03 );
	};

	/* A file that will not play says so through the `error` event; the promise has nothing to add. */
	Mixer.prototype._start = function () {
		var played = this.audio.play();
		if ( played && played.catch ) {
			played.catch( function () {} );
		}
	};

	/* The microphone. Measured before the fader, so the meter shows a voice even while muted. */
	Mixer.prototype.setMic = function ( stream ) {
		if ( this._micNode ) {
			this._micNode.disconnect();
			this._micNode = null;
		}
		if ( this._micStream && this._micStream !== stream ) {
			this._micStream.getTracks().forEach( function ( t ) {
				t.stop();
			} );
		}
		this._micStream = stream;
		if ( stream && stream.getAudioTracks().length ) {
			this._micNode = this.ctx.createMediaStreamSource( stream );
			this._micNode.connect( this.levels.mic.meter );
			this._micNode.connect( this.buses.mic );
		}
	};

	/* The sound of a shared tab or window, when the browser offered it. */
	Mixer.prototype.setScreen = function ( stream ) {
		if ( this._screenNode ) {
			this._screenNode.disconnect();
			this._screenNode = null;
		}
		if ( stream && stream.getAudioTracks().length ) {
			this._screenNode = this.ctx.createMediaStreamSource( stream );
			this._screenNode.connect( this.buses.screen );
		}
	};

	Mixer.prototype.level = function ( bus ) {
		var entry = this.levels[ bus ];
		if ( ! entry ) {
			return 0;
		}
		entry.meter.getByteTimeDomainData( entry.data );
		var peak = 0;
		for ( var i = 0; i < entry.data.length; i += 4 ) {
			var v = Math.abs( entry.data[ i ] - 128 );
			if ( v > peak ) {
				peak = v;
			}
		}
		return Math.min( 1, peak / 96 );
	};

	/* Called every few frames: the music sits lower while somebody is talking, then comes back. */
	Mixer.prototype.tick = function () {
		var talking = this.duck && ! this.muted && this.level( 'mic' ) > 0.14;
		var now = this.ctx.currentTime;
		if ( talking ) {
			this._talkUntil = now + 0.9;
		}
		var want = now < ( this._talkUntil || 0 );
		if ( want !== this._ducked ) {
			this._ducked = want;
			this.buses.music.gain.setTargetAtTime( this.volumes.music * ( want ? 0.3 : 1 ), now, want ? 0.08 : 0.5 );
		}
	};

	/* ─────────────────────────── music ─────────────────────────── */

	Mixer.prototype.setPlaylist = function ( list ) {
		this.playlist = list.slice();
		if ( this.index >= this.playlist.length ) {
			this.index = 0;
		}
	};

	Mixer.prototype.playIndex = function ( i ) {
		var item = this.playlist[ i ];
		if ( ! item ) {
			return;
		}
		this.wake();
		this.index = i;
		if ( this.audio.src !== item.url ) {
			this.audio.src = item.url;
		}
		this._start();
	};

	Mixer.prototype.toggle = function () {
		this.wake();
		if ( ! this.audio.paused ) {
			this.audio.pause();
		} else if ( this.audio.src ) {
			this._start();
		} else {
			this.playIndex( this.index );
		}
	};

	Mixer.prototype.next = function ( auto ) {
		if ( ! this.playlist.length ) {
			return;
		}
		var i = this.index + 1;
		if ( i >= this.playlist.length ) {
			if ( auto && ! this.loop ) {
				if ( this.onMusic ) {
					this.onMusic();
				}
				return;
			}
			i = 0;
		}
		this.playIndex( i );
	};

	Mixer.prototype.stop = function () {
		this.audio.pause();
		if ( this.audio.src ) {
			this.audio.currentTime = 0;
		}
	};

	/* ─────────────────────────── pads ─────────────────────────── */

	Mixer.prototype._tone = function ( freq, at, len, volume, type ) {
		var ctx = this.ctx;
		var osc = ctx.createOscillator();
		var gain = ctx.createGain();
		osc.type = type || 'sine';
		osc.frequency.value = freq;
		gain.gain.setValueAtTime( 0.0001, at );
		gain.gain.exponentialRampToValueAtTime( volume, at + 0.008 );
		gain.gain.exponentialRampToValueAtTime( 0.0001, at + len );
		osc.connect( gain );
		gain.connect( this.buses.pads );
		osc.start( at );
		osc.stop( at + len + 0.05 );
	};

	Mixer.prototype._noise = function ( at, len, volume, filterType, freq, freqTo ) {
		var ctx = this.ctx;
		if ( ! this._noiseBuffer ) {
			var size = ctx.sampleRate * 2;
			this._noiseBuffer = ctx.createBuffer( 1, size, ctx.sampleRate );
			var data = this._noiseBuffer.getChannelData( 0 );
			for ( var i = 0; i < size; i++ ) {
				data[ i ] = Math.random() * 2 - 1;
			}
		}
		var src = ctx.createBufferSource();
		src.buffer = this._noiseBuffer;
		src.loop = true;
		var filter = ctx.createBiquadFilter();
		filter.type = filterType;
		filter.frequency.setValueAtTime( freq, at );
		if ( freqTo ) {
			filter.frequency.exponentialRampToValueAtTime( freqTo, at + len );
		}
		var gain = ctx.createGain();
		gain.gain.setValueAtTime( 0.0001, at );
		gain.gain.exponentialRampToValueAtTime( volume, at + Math.min( 0.02, len / 3 ) );
		gain.gain.exponentialRampToValueAtTime( 0.0001, at + len );
		src.connect( filter );
		filter.connect( gain );
		gain.connect( this.buses.pads );
		src.start( at, Math.random() );
		src.stop( at + len + 0.05 );
	};

	Mixer.prototype.playPad = function ( key ) {
		this.wake();
		var t = this.ctx.currentTime + 0.01;
		switch ( key ) {
			case 'ding':
				this._tone( 1318.5, t, 1.1, 0.5 );
				this._tone( 2637, t, 0.5, 0.14 );
				break;
			case 'sold':
				this._noise( t, 0.05, 0.35, 'highpass', 3000 );
				this._tone( 1975.5, t + 0.05, 0.28, 0.42 );
				this._tone( 2637, t + 0.17, 0.8, 0.46 );
				this._tone( 3951, t + 0.17, 0.4, 0.14 );
				break;
			case 'tada':
				[ 523.25, 659.25, 783.99 ].forEach( function ( f ) {
					this._tone( f, t, 0.14, 0.2, 'triangle' );
				}, this );
				[ 523.25, 659.25, 783.99, 1046.5 ].forEach( function ( f ) {
					this._tone( f, t + 0.17, 1, 0.22, 'triangle' );
				}, this );
				break;
			default:
				this._playOwn( key );
		}
	};

	/* A recording: fetched and decoded once, then instant. */
	Mixer.prototype._load = function ( url ) {
		var self = this;
		if ( ! this.padBuffers[ url ] ) {
			this.padBuffers[ url ] = window.fetch( url, { credentials: 'same-origin' } ).then( function ( r ) {
				return r.arrayBuffer();
			} ).then( function ( bytes ) {
				return self.ctx.decodeAudioData( bytes );
			} ).catch( function ( err ) {
				delete self.padBuffers[ url ]; // a blip should not break this pad for the rest of the stream
				throw err;
			} );
		}
		return this.padBuffers[ url ];
	};

	/* Ready in advance, so the first press is as quick as the rest. */
	Mixer.prototype.preload = function ( url ) {
		this._load( url ).catch( function () {} );
	};

	Mixer.prototype._playOwn = function ( url ) {
		var self = this;
		this._load( url ).then( function ( buffer ) {
			var src = self.ctx.createBufferSource();
			src.buffer = buffer;
			src.connect( self.buses.pads );
			src.start();
		} ).catch( function () {
			if ( self.onError ) {
				self.onError();
			}
		} );
	};

	root.WHDMixer = { Mixer: Mixer, PADS: PADS, supported: !! AudioCtx };
}( window ) );
