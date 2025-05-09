import './bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

document.addEventListener('DOMContentLoaded', function() {
    // 提交批量创建表单后处理成功消息
    if (document.getElementById('batchMailUserForm')) {
        document.getElementById('batchMailUserForm').addEventListener('submit', function() {
            // 显示加载指示器
            if (!document.getElementById('loadingOverlay')) {
                var loadingOverlay = document.createElement('div');
                loadingOverlay.id = 'loadingOverlay';
                loadingOverlay.innerHTML = '<div class="spinner-border text-light" role="status"><span class="visually-hidden">Loading...</span></div><div class="mt-2 text-light">正在处理，请稍候...</div>';
                loadingOverlay.style.position = 'fixed';
                loadingOverlay.style.top = '0';
                loadingOverlay.style.left = '0';
                loadingOverlay.style.width = '100%';
                loadingOverlay.style.height = '100%';
                loadingOverlay.style.backgroundColor = 'rgba(0,0,0,0.5)';
                loadingOverlay.style.display = 'flex';
                loadingOverlay.style.flexDirection = 'column';
                loadingOverlay.style.justifyContent = 'center';
                loadingOverlay.style.alignItems = 'center';
                loadingOverlay.style.zIndex = '9999';
                document.body.appendChild(loadingOverlay);
            } else {
                document.getElementById('loadingOverlay').style.display = 'flex';
            }
        });
    }
    
    // 处理成功消息的显示
    document.addEventListener('DOMContentLoaded', function() {
        const successAlert = document.querySelector('.alert-success');
        if (successAlert) {
            window.scrollTo(0, 0);
            setTimeout(function() {
                successAlert.classList.add('fade-out');
                setTimeout(function() {
                    successAlert.remove();
                }, 500);
            }, 5000);
        }
    });
    
    // 为所有.btn-copy添加点击事件处理器
    document.querySelectorAll('.btn-copy').forEach(function(button) {
        button.addEventListener('click', function() {
            const text = this.getAttribute('data-clipboard-text');
            navigator.clipboard.writeText(text).then(function() {
                // 更改按钮文本为"已复制"
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="bi bi-check-circle"></i> 已复制';
                
                // 3秒后恢复原始文本
                setTimeout(function() {
                    button.innerHTML = originalText;
                }, 3000);
            }).catch(function(err) {
                console.error('无法复制文本: ', err);
                alert('复制失败，请手动复制');
            });
        });
    });
});
