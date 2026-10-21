/**
 * AI landing page (Weline_Ai): particle background + scroll reveal.
 * Moved verbatim from the inline <script> in templates/frontend/Index/index.phtml.
 * Registered as module `aiIndex`; the particles container declares data-weline-load.
 */
// 创建粒子背景
        function createParticles() {
            const particles = document.getElementById('particles');
            const particleCount = 50;

            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDelay = Math.random() * 20 + 's';
                particle.style.animationDuration = (Math.random() * 10 + 15) + 's';
                particles.appendChild(particle);
            }
        }

        // 滚动动画
        function handleScroll() {
            const elements = document.querySelectorAll('.fade-in');
            elements.forEach(el => {
                const rect = el.getBoundingClientRect();
                const isVisible = rect.top < window.innerHeight - 100;
                if (isVisible) {
                    el.classList.add('visible');
                }
            });
        }

        // 初始化
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
            handleScroll();
            window.addEventListener('scroll', handleScroll);
        });
