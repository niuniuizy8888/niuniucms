        // 控制开关（0开启、1关闭）
        var config = window.__DANMU_CONFIG__ || {};
        // player_urls 由 player.html <head> 注入（route/play.php 经 $play_json_safe 交给模板）
        var nid = config.ep;
        var nextNid = nid + 1;
        var vodId = config.vid;
                const danmakuUrl = config.danmu_list + '?vid=' + vodId + '&max=1000&format=xml';
        var currentEpisode = player_urls[nid - 1] || player_urls[0];
        var videoUrl = currentEpisode ? currentEpisode.play_url : "";
        // ---- 弹幕发送令牌：页面内嵌值优先；为空或被拒时改去 POST 端点现签 ----
        // 匿名访客命中的是 nginx L0 缓存页（该 location 用 fastcgi_hide_header 剥掉了 Set-Cookie），
        // 页面里的 danmu_token 属于一个浏览器并不知道的会话 → 直接发必报「非法操作」。
        // 令牌端点是 POST，天然绕过 L0 回源：会真正下发 nncms_sid，并把令牌环写进这份会话。
        var danmuToken = config.danmu_token || "";
        var danmuTokenFetches = 0;
        async function ensureDanmuToken(force) {
            if (danmuToken && !force) {
                return danmuToken;
            }
            if (!config.danmu_token_api || danmuTokenFetches >= 2) {
                return danmuToken;
            }
            danmuTokenFetches++;
            try {
                const resp = await fetch(config.danmu_token_api, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'v=' + encodeURIComponent(vodId)
                });
                const j = await resp.json();
                if (j && j.code === 1 && j.token) {
                    danmuToken = j.token;
                }
            } catch (error) {
                console.error('获取弹幕令牌失败:', error);
            }
            return danmuToken;
        }
        function playM3u8(video, url, art) {
            if (Hls.isSupported()) {
                if (art.hls) art.hls.destroy();
                const hls = new Hls();
                hls.loadSource(url);
                hls.attachMedia(video);
                art.hls = hls;
                art.on('destroy', () => hls.destroy());
                /*hls.on(Hls.Events.MANIFEST_PARSED, function () {
                    art.play();
                }*);*/
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = url;
                /*video.onloadeddata = function () {
                    art.play();
                };*/
                video.load();
            } else {
                art.notice.show = '不支持的播放格式：m3u8';
            }
        }
        function parseXMLDanmu(xmlText) {
            try {
                const parser = new DOMParser();
                const xmlDoc = parser.parseFromString(xmlText, "text/xml");
                const dElements = xmlDoc.getElementsByTagName('d');
                return Array.from(dElements).map(function (dElement) {
                    const p = dElement.getAttribute('p');
                    if (!p) return null;
                    const parts = p.split(',');
                    if (parts.length < 4) return null;
                    const time = parseFloat(parts[0]);
                    if (isNaN(time)) return null;
                    const mode = parseInt(parts[1]);
                    const color = parts[3];
                    const text = dElement.textContent || '';
                    let artMode = 0;
                    if (mode === 5) artMode = 1;
                    else if (mode === 4) artMode = 2;
                    let artColor = '#FFFFFF';
                    const colorInt = parseInt(color);
                    if (!isNaN(colorInt)) {
                        artColor = '#' + colorInt.toString(16).padStart(6, '0').toUpperCase();
                    }
                    return {
                        text: text,
                        time: time,
                        color: artColor,
                        mode: artMode
                    };
                }).filter(Boolean);
            } catch (error) {
                console.error('解析XML弹幕失败:', error);
                return [];
            }
        }
        function createPauseAd() {
            const playerContainer = document.getElementById('artplayer');
            if (!playerContainer) return;
            playerContainer.style.position = 'relative';
            const adHTML = `
                <div id="pauseAdContainer" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: none; align-items: center; justify-content: center; z-index: 9999; cursor: pointer; overflow: hidden;">
                    <div style="position: relative; max-width: 80%; max-height: 80%; transform: translateY(-10px); animation: fadeIn 0.5s ease-out; box-sizing: border-box;">
                        <img alt="广告" src="config.stop_ad" style="max-width: 100%; max-height: 280px; object-fit: contain; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); border: 3px solid rgba(255,255,255,0.1); backdrop-filter: blur(2px); box-sizing: border-box;">
                        <button id="closeAdButton" style="position: absolute; top: -15px; right: -15px; background: linear-gradient(135deg, #ff6b6b, #ee5a52); color: white; border: 2px solid white; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 14px; font-weight: bold; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 10px rgba(0,0,0,0.2); transition: all 0.3s ease; font-family: Arial, sans-serif; box-sizing: border-box;">✕</button>
                      <div id="pauseAdText" style="position: absolute; bottom: -35px; left: 50%; transform: translateX(-50%); background: linear-gradient(135deg, #2ed573, #19b886); color: white; font-size: 14px; padding: 8px 20px; border-radius: 20px; box-shadow: 0 4px 12px rgba(46, 213, 115, 0.4); cursor: pointer; transition: all 0.3s ease; white-space: nowrap; box-sizing: border-box;">点击继续播放</div>
                    </div>
                </div>
            `;
            playerContainer.insertAdjacentHTML('beforeend', adHTML);
            function applyResponsiveStyles() {
                const playerContainer = document.getElementById('artplayer');
                if (!playerContainer) return;
                const pauseAd = document.getElementById('pauseAdContainer');
                const adContent = document.querySelector('#pauseAdContainer > div');
                const adImage = document.querySelector('#pauseAdContainer img');
                const closeBtn = document.getElementById('closeAdButton');
                const adText = document.getElementById('pauseAdText');
                const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                const isSmallScreen = window.innerWidth < 768;
                const isMobileOrSmall = isMobile || isSmallScreen;
                if (pauseAd) {
                    pauseAd.style.overflow = 'hidden';
                }
                if (adContent) {
                    adContent.style.maxWidth = isMobileOrSmall ? '90%' : '80%';
                    adContent.style.maxHeight = isMobileOrSmall ? '60%' : '80%';
                    adContent.style.boxSizing = 'border-box';
                    adContent.style.overflow = 'visible';
                }
                if (adImage) {
                    adImage.style.maxWidth = '100%';
                    adImage.style.maxHeight = isMobileOrSmall ? '180px' : '280px';
                    adImage.style.objectFit = 'contain';
                    adImage.style.borderRadius = isMobileOrSmall ? '8px' : '12px';
                    adImage.style.borderWidth = isMobileOrSmall ? '2px' : '3px';
                    adImage.style.boxSizing = 'border-box';
                }
                if (closeBtn) {
                    closeBtn.style.width = isMobileOrSmall ? '28px' : '32px';
                    closeBtn.style.height = isMobileOrSmall ? '28px' : '32px';
                    closeBtn.style.top = isMobileOrSmall ? '-12px' : '-15px';
                    closeBtn.style.right = isMobileOrSmall ? '-12px' : '-15px';
                    closeBtn.style.fontSize = isMobileOrSmall ? '12px' : '14px';
                    closeBtn.style.boxSizing = 'border-box';
                    const maxOffset = isMobileOrSmall ? '12px' : '15px';
                    closeBtn.style.maxWidth = `calc(100% + ${maxOffset})`;
                    closeBtn.style.maxHeight = `calc(100% + ${maxOffset})`;
                }
                if (adText) {
                    adText.style.bottom = isMobileOrSmall ? '-28px' : '-35px';
                    adText.style.fontSize = isMobileOrSmall ? '12px' : '14px';
                    adText.style.whiteSpace = 'nowrap';
                    adText.style.boxSizing = 'border-box';
                }
            }
            document.addEventListener('DOMContentLoaded', applyResponsiveStyles);
            applyResponsiveStyles();
            window.addEventListener('resize', applyResponsiveStyles);
            setTimeout(function () {
                const pauseAd = document.getElementById('pauseAdContainer');
                const closeButton = document.getElementById('closeAdButton');
                if (pauseAd && closeButton) { 
                    const adImage = pauseAd.querySelector('img');
                    if (adImage) {
                        adImage.onclick = function () {
                            // 修复：原为字符串字面量 'config.stop_ad_url'（永不生效，点击打开站内相对路径）。
                            // 与开始广告的 `url: config.start_ad_url`（见下方 __o 装配）保持同一取值口径。
                            if (config.stop_ad_url) window.open(config.stop_ad_url, '_blank');
                        };
                    } 
                    const pauseAdText = document.getElementById('pauseAdText');
                    if (pauseAdText) {
                        pauseAdText.onclick = function (e) {
                            e.stopPropagation();
                            pauseAd.style.display = 'none';
                            if (window.art) {
                                window.art.play();
                            }
                        };
                    }
                    closeButton.onclick = function (e) {
                        e.stopPropagation();
                        pauseAd.style.display = 'none';
                    };
                    closeButton.addEventListener('mouseenter', function () {
                        closeButton.style.transform = 'scale(1.15) rotate(90deg)';
                        closeButton.style.boxShadow = '0 6px 20px rgba(0,0,0,0.4)';
                        closeButton.style.background = 'linear-gradient(135deg, #ff4757, #c44569)';
                    });
                    closeButton.addEventListener('mouseleave', function () {
                        closeButton.style.transform = 'scale(1) rotate(0deg)';
                        closeButton.style.boxShadow = '0 2px 10px rgba(0,0,0,0.2)';
                        closeButton.style.background = 'linear-gradient(135deg, #ff6b6b, #ee5a52)';
                    });
                }
            }, 100);
        }
        var art = new Artplayer({
            container: '#artplayer',
            url: videoUrl,
            controls: [
                (config.select_col === 0) ? {
                    name: 'episode',
                    position: 'right',
                    html: '<span style="padding: 0 8px; cursor: pointer;">选集</span>',
                    tooltip: '选择集数',
                    style: {
                        marginRight: '10px',
                        cursor: 'pointer'
                    }
                } : null
            ].filter(Boolean),
            volume: 0.8,
            muted: false,
            autoSize: false,
            autoMini: true,
            type: 'm3u8',
            customType: { m3u8: playM3u8 },
            setting: true,
            settings: [
                {
                    html: '画中画',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 1024 1024"><path d="M844.8 219.648H179.2c-6.144 0-10.24 4.608-10.24 10.752v563.2c0 5.632 4.096 10.24 10.24 10.24h256V896h-256A102.4 102.4 0 0 1 76.8 793.6V230.4c0-56.832 45.568-102.4 102.4-102.4h665.6a102.4 102.4 0 0 1 102.4 102.4v204.8h-92.16V230.4c0-6.144-4.608-10.752-10.24-10.752M614.4 588.8c-28.672 0-51.2 22.528-51.2 51.2v204.8c0 28.16 22.528 51.2 51.2 51.2H896c28.16 0 51.2-23.04 51.2-51.2V640c0-28.672-23.04-51.2-51.2-51.2Z"></path></svg>',
                    switch: false,
                    onSwitch: function (item, $dom, event) {
                        const nextState = !item.switch;
                        art.pip = nextState;
                        item.tooltip = nextState ? '开启画中画' : '关闭画中画';
                        return nextState;
                    }
                },
                {
                    html: '网页全屏',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" class="icon" viewBox="0 0 1152 1024"><path d="M1075.2 0H76.8A76.8 76.8 0 0 0 0 76.8v870.4a76.8 76.8 0 0 0 76.8 76.8h998.4a76.8 76.8 0 0 0 76.8-76.8V76.8A76.8 76.8 0 0 0 1075.2 0M1024 128v768H128V128Zm-576 64a64 64 0 0 1 7.488 127.552L448 320H320v128a64 64 0 0 1-56.512 63.552L256 512a64 64 0 0 1-63.552-56.512L192 448V262.592c0-34.432 25.024-66.112 61.632-70.144l8-.448Zm256 640a64 64 0 0 1-7.488-127.552L704 704h128V576a64 64 0 0 1 56.512-63.552L896 512a64 64 0 0 1 63.552 56.512L960 576v185.408c0 34.496-25.024 66.112-61.632 70.208l-8 .384Z"></path></svg>',
                    switch: false,
                    onSwitch: function (item, $dom, event) {
                        const nextState = !item.switch;
                        art.fullscreenWeb = nextState;
                        item.tooltip = nextState ? '开启网页全屏' : '关闭网页全屏';
                        return nextState;
                    }
                },
                {
                    html: '全屏',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" class="icon" viewBox="0 0 1024 1024"><path d="M625.778 256H768v142.222h113.778v-256h-256ZM256 398.222V256h142.222V142.222h-256v256Zm512 227.556V768H625.778v113.778h256v-256ZM398.222 768H256V625.778H142.222v256h256Z"></path></svg>',
                    switch: false,
                    onSwitch: function (item, $dom, event) {
                        const nextState = !item.switch;
                        art.fullscreen = nextState;
                        item.tooltip = nextState ? '开启全屏' : '关闭全屏';
                        return nextState;
                    }
                }
            ],
            flip: true,
            playbackRate: true,
            aspectRatio: true,
            fullscreenWeb: true,
            autoOrientation: true,
            id: "vod_" + vodId,
            autoPlayback: true,
                        plugins: [
                ...(config.start_ad_on === 0 ? [artplayerPluginAds((function(){
                    var __o = {
                        url: config.start_ad_url,
                        playDuration: config.start_close_time,
                        totalDuration: config.start_all_time,
                        i18n: {
                            close: '关闭广告',
                            countdown: '%s秒',
                            detail: '查看详情',
                            canBeClosed: '%s秒后可关闭广告'
                        }
                    };
                    if (config.start_ad === 0) { __o.html = '<img alt="广告" src="' + config.start_img_ad + '">'; }
                    else if (config.start_ad === 1) { __o.video = config.start_mp4_ad; }
                    return __o;
                })())] : []),
(config.danmu_toggle === 0) ? artplayerPluginDanmuku({
                    danmuku: async () => {
                        try {
                            const response = await fetch(danmakuUrl);
                            const xmlText = await response.text();
                            const danmuList = parseXMLDanmu(xmlText);
                            return danmuList;
                        } catch (error) {
                            console.error('加载弹幕失败:', error);
                            return [];
                        }
                    },
                    speed: 5,
                    margin: [10, '25%'],
                    opacity: 1,
                    mode: 0,
                    modes: [0, 1, 2],
                    fontSize: 25,
                    antiOverlap: true,
                    visible: true,
                    emitter: true,
                    theme: 'dark',
                    beforeEmit: async function (danmu) {
                        if (!danmu.text || danmu.text.trim().length === 0) {
                            art.notice.show = '请输入弹幕内容';
                            return false;
                        }
                        const danmuColor = document.getElementById('danmu_color');
                        const currentColor = danmuColor ? danmuColor.value : (danmu.color || '#FFFFFF');
                        const sendData = {
                            text: danmu.text,
                            time: danmu.time || art.currentTime,
                            color: currentColor,
                            mode: danmu.mode || 0,
                            vod_id: vodId,
                            id: vodId
                        };
                        const postDanmu = async function (token) {
                            sendData.safe_token = token;
                            const response = await fetch(config.danmu_send, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams(sendData)
                            });
                            if (!response.ok) throw new Error('HTTP error! status: ' + response.status);
                            return await response.json();
                        };
                        try {
                            let data = await postDanmu(await ensureDanmuToken(false));
                            if (data && data.code !== 1 && danmuToken) {
                                // 令牌被服务端判非法（旧会话/L0 缓存页里的他人令牌）→ 现签一次再试，仅一次
                                danmuToken = '';
                                const fresh = await ensureDanmuToken(true);
                                if (fresh) {
                                    data = await postDanmu(fresh);
                                }
                            }
                            if (data.code === 1) {
                                art.notice.show = '弹幕发送成功';
                                return true;
                            } else {
                                art.notice.show = '弹幕发送失败: ' + (data.msg || '未知错误');
                                return false;
                            }
                        } catch (error) {
                            console.error('发送弹幕失败:', error);
                            art.notice.show = '网络错误，请稍后重试';
                            return false;
                        }
                    }
                }) : null
            ].filter(Boolean)
        });
        if (config.pause_ad === 0) {
            createPauseAd();
        }
        art.on('pause', function () {
            if (config.pause_ad === 1) {
                return;
            }
            const pauseAd = document.getElementById('pauseAdContainer');
            if (pauseAd) {
                pauseAd.style.display = 'flex';
            }
        });
        art.on('play', function () {
            const pauseAd = document.getElementById('pauseAdContainer');
            if (pauseAd) pauseAd.style.display = 'none';
        });
        art.on('video:ended', function () {
            if (nextNid <= player_urls.length) {
                art.notice.show = "即将播放下一集…";
                setTimeout(function () {
                    try {
                        var nextEpisode = player_urls[nextNid - 1];
                        if (nextEpisode && nextEpisode.play_url) { 
                            if (art.video) {
                                art.video.currentTime = 0;
                            }
                            art.url = nextEpisode.play_url;
                            if (art.video) {
                                art.video.src = nextEpisode.play_url;
                                art.video.load();   
                                art.video.onloadeddata = function () {
                                    art.video.play().catch(function (e) {
                                        console.warn('自动播放被阻止，可能需要用户交互:', e);
                                        art.notice.show = '请点击播放下一集';
                                    });
                                };
                            }
                            nid = nextNid;
                            nextNid = nid + 1;
                        } else {
                            console.error('无法获取下一集的m3u8地址');
                            art.notice.show = '该集暂无播放地址';
                        }
                    } catch (error) {
                        console.error('切换到下一集时出错:', error);
                        art.notice.show = '切换下一集失败';
                    }
                }, 1500);
            } else {
                art.notice.show = "已经是最后一集";
            }
        });
        art.on('control:episode:click', function () {
            showEpisodePanel();
        });
        setTimeout(function () {
            $('.art-control-episode').on('click', function () {
                showEpisodePanel();
            });
            $('.art-control-episode span').on('click', function (e) {
                e.stopPropagation(); // 阻止事件冒泡
                showEpisodePanel();
            });
        }, 1000);
        function showEpisodePanel() {
            var $playerContainer = $('#artplayer');
            if (!$playerContainer.length) {
                console.error('播放器容器不存在');
                return;
            }
            $playerContainer.css('position', 'relative');
            var $panel = $('#customEpisodePanel');
            if ($panel.length === 0) {
                $panel = $('<div id="customEpisodePanel"></div>');
                $panel.css({
                    'position': 'absolute',
                    'top': '0',
                    'right': '-300px',
                    'width': '360px',
                    'background': 'rgba(0, 0, 0, 0.7)',
                    'color': 'white',
                    'z-index': '100',
                    'padding': '15px',
                    'box-sizing': 'border-box',
                    'overflow-y': 'auto',
                    'display': 'none',
                    'transition': 'right 0.3s ease-in-out',
                    'height': '100%'
                }); 
                var $title = $('<h3>选集</h3>');
                $title.css({
                    'margin-top': '0',
                    'margin-bottom': '15px',
                    'font-size': '16px'
                });
                $panel.append($title); 
                var $closeBtn = $('<button>✕</button>');
                $closeBtn.css({
                    'position': 'absolute',
                    'top': '15px',
                    'right': '15px',
                    'background': 'rgba(255, 255, 255, 0.2)',
                    'color': 'white',
                    'border': 'none',
                    'border-radius': '50%',
                    'width': '24px',
                    'height': '24px',
                    'cursor': 'pointer',
                    'font-size': '16px',
                    'display': 'flex',
                    'align-items': 'center',
                    'justify-content': 'center',
                    'line-height': '1'
                });
                
                $closeBtn.on('click', function () { 
                    $panel.css('right', '-300px');
                    setTimeout(() => {
                        $panel.hide();
                    }, 300);
                });
                $panel.append($closeBtn); 
                var $episodeContainer = $('<div class="episode-container"></div>');
                $episodeContainer.css({
                    'display': 'grid',
                    'gridTemplateColumns': 'repeat(3, 1fr)',
                    'gap': '8px',
                    'height': 'auto',
                    'maxHeight': '300px',
                    'overflowY': 'auto',
                    'scrollbarWidth': 'thin',
                    'scrollbarColor': 'rgba(255, 255, 255, 0.3) rgba(0, 0, 0, 0.2)'
                }); 
                $('<style>')
                    .text('.episode-container::-webkit-scrollbar { width: 6px; } .episode-container::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.2); } .episode-container::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.3); border-radius: 3px; } .episode-container::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.5); }')
                    .appendTo('head');
                $panel.append($episodeContainer);
                $playerContainer.append($panel);
            }
            loadEpisodes($panel.find('.episode-container')); 
            $panel.show(); 
            setTimeout(() => {
                $panel.css('right', '0');
            }, 10); 
            $panel.css('z-index', '100');
        } 
        function loadEpisodes($container) {
            $container.empty();
            if (player_urls && player_urls.length > 0) {
                player_urls.forEach(function (episode, index) {
                    var episodeId = index + 1;
                    var isCurrent = (episodeId === nid);
                    var $episodeBtn = $('<button></button>').text(episode.name);
                    $episodeBtn.css({
                        'background': isCurrent ? '#ff4757' : '#333',
                        'color': 'white',
                        'border': 'none',
                        'padding': '8px 16px',
                        'border-radius': '4px',
                        'cursor': 'pointer',
                        'min-width': '80px',
                        'text-align': 'center'
                    });
                     
                    $episodeBtn.data('episodeId', episodeId);
                    $episodeBtn.on('click', function () {
                        var episodeId = $(this).data('episodeId');
                        switchEpisode(episodeId);
                    });
                    
                    $container.append($episodeBtn);
                });
            } else {
                $container.append($('<div style="color: white; padding: 10px; text-align: center;">暂无集数数据</div>'));
            }
        }
        // 切换集数
        function switchEpisode(episodeId) {
            var episodeIndex = episodeId - 1;
            var currentEpisode = player_urls[episodeIndex];
            try { 
                if (art && art.notice) {
                    art.notice.show = '正在切换到第' + episodeId + '集...';
                } 
                if (!currentEpisode || !currentEpisode.play_url) {
                    console.error('无效的视频URL');
                    if (art && art.notice) {
                        art.notice.show = '该集暂无播放地址';
                    }
                    return;
                } 
                var episodeUrl = currentEpisode.play_url; 
                art.url = episodeUrl; 
                if (art.video) {
                    art.video.currentTime = 0;
                }
                 
                if (art.video) {
                    art.video.src = episodeUrl;
                    art.video.load(); 
                    art.video.onloadeddata = function () {
                        art.video.play().catch(function (e) {
                            console.warn('自动播放被阻止，可能需要用户交互:', e);
                            art.notice.show = '请点击播放该集';
                        });
                    };
                }
                nid = episodeId;
                nextNid = nid + 1; 
                var $panel = $('#customEpisodePanel');
                if ($panel.length > 0) {
                    $panel.css('right', '-300px');
                    setTimeout(() => {
                        $panel.hide();
                    }, 300);
                }
            } catch (error) {
                console.error('切换集数时出错:', error);
                if (art && art.notice) {
                    art.notice.show = '切换集数失败';
                }
            }
        }
    