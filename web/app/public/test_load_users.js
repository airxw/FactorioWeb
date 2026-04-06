// 测试加载用户列表的脚本
console.log('开始测试加载用户列表...');

async function testLoadUserList() {
    try {
        const res = await fetch('/app/api.php?action=user_list');
        console.log('API响应状态:', res.status);
        
        const d = await res.json();
        console.log('API响应数据:', d);
        
        if (d.success && (d.users || (d.data && d.data.users))) {
            const users = d.users || d.data.users;
            console.log('找到', users.length, '个用户:', users);
            
            const select = document.getElementById('set-vip-username');
            if (select) {
                select.innerHTML = '<option value="">-- 选择用户 --</option>';
                users.forEach(u => {
                    const option = document.createElement('option');
                    option.value = u.username;
                    option.textContent = `${u.username} (${u.name})`;
                    select.appendChild(option);
                });
                console.log('用户列表已成功加载到下拉框');
            } else {
                console.error('找不到set-vip-username元素');
            }
        } else {
            console.error('API返回失败或没有用户数据');
        }
    } catch(e) {
        console.error('加载用户列表时出错:', e);
    }
}

// 执行测试
testLoadUserList();