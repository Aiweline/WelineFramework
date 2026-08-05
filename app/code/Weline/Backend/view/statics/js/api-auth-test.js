
(function(g){
  g.bqAdmin=g.bqAdmin||{};
  g.bqAdmin['backend_admin']=function(url, options){
    options=options||{};
    var body=options.body;
    if(body && typeof FormData!=='undefined' && body instanceof FormData){
      var p=new URLSearchParams(); body.forEach(function(v,k){ if(!(typeof File!=='undefined'&&v instanceof File)) p.append(k,String(v)); }); body=p.toString();
    } else if(body && typeof body!=='string'){ try{ body=JSON.stringify(body); }catch(e){ body=''; } }
    var run=function(api){ return api.resource('backend_admin').adminRequest({url:url, method:options.method||'POST', headers:options.headers||{}, body:body||''}); };
    var toResp=function(data){
      var _biz=g.WelineApiBusiness||(g.Weline&&g.Weline.ApiBusiness);
      if(_biz&&typeof _biz.wrapAdminBridgeResult==='function'){
        return _biz.wrapAdminBridgeResult(data);
      }
      var body=(data&&typeof data==='object'&&!Array.isArray(data))?data:{success:true,data:data};
      var ok=!(body&&body.success===false);
      var resp={ok:ok,status:ok?200:400,json:function(){return Promise.resolve(body);},text:function(){return Promise.resolve(typeof body==='string'?body:JSON.stringify(body==null?{}:body));}};
      Object.keys(body).forEach(function(k){ if(k==='ok'||k==='json'||k==='text'||k==='status') return; resp[k]=body[k]; });
      return resp;
    };
    var p=(g.Weline&&g.Weline.load)?g.Weline.load('api').then(run):Promise.resolve(run(g.Weline.Api));
    return p.then(toResp);
  };
})(typeof window!=='undefined'?window:globalThis);
/**
 * API认证测试工具
 */
class ApiAuthTest {
    constructor(baseUrl = '') {
        this.baseUrl = baseUrl || window.site?.api_host || '/api/backend/';
        this.token = localStorage.getItem('api_token');
    }

    /**
     * 登录并获取token
     */
    async login(username, password, expireTime = 0) {
        try {
            const response = await bqAdmin['backend_admin'](this.baseUrl + 'auth/login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    username: username,
                    password: password,
                    expire_time: expireTime
                })
            });

            const result = await response.json();
            
            if (result.code === 200) {
                this.token = result.data.token;
                localStorage.setItem('api_token', this.token);
                console.log('登录成功:', result);
                return result;
            } else {
                console.error('登录失败:', result);
                throw new Error(result.msg);
            }
        } catch (error) {
            console.error('登录请求失败:', error);
            throw error;
        }
    }

    /**
     * 刷新token
     */
    async refreshToken(expireTime = 0) {
        if (!this.token) {
            throw new Error('没有可用的token');
        }

        try {
            const response = await bqAdmin['backend_admin'](this.baseUrl + 'auth/refresh', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + this.token
                },
                body: JSON.stringify({
                    expire_time: expireTime
                })
            });

            const result = await response.json();
            
            if (result.code === 200) {
                this.token = result.data.token;
                localStorage.setItem('api_token', this.token);
                console.log('Token刷新成功:', result);
                return result;
            } else {
                console.error('Token刷新失败:', result);
                throw new Error(result.msg);
            }
        } catch (error) {
            console.error('Token刷新请求失败:', error);
            throw error;
        }
    }

    /**
     * 获取当前用户信息
     */
    async getCurrentUser() {
        if (!this.token) {
            throw new Error('没有可用的token');
        }

        try {
            const response = await bqAdmin['backend_admin'](this.baseUrl + 'auth/me', {
                method: 'GET',
                headers: {
                    'Authorization': 'Bearer ' + this.token
                }
            });

            const result = await response.json();
            
            if (result.code === 200) {
                console.log('获取用户信息成功:', result);
                return result;
            } else {
                console.error('获取用户信息失败:', result);
                throw new Error(result.msg);
            }
        } catch (error) {
            console.error('获取用户信息请求失败:', error);
            throw error;
        }
    }

    /**
     * 获取token信息
     */
    async getTokenInfo() {
        if (!this.token) {
            throw new Error('没有可用的token');
        }

        try {
            const response = await bqAdmin['backend_admin'](this.baseUrl + 'auth/token-info', {
                method: 'GET',
                headers: {
                    'Authorization': 'Bearer ' + this.token
                }
            });

            const result = await response.json();
            
            if (result.code === 200) {
                console.log('获取token信息成功:', result);
                return result;
            } else {
                console.error('获取token信息失败:', result);
                throw new Error(result.msg);
            }
        } catch (error) {
            console.error('获取token信息请求失败:', error);
            throw error;
        }
    }

    /**
     * 登出
     */
    async logout() {
        if (!this.token) {
            console.log('没有可用的token');
            return;
        }

        try {
            const response = await bqAdmin['backend_admin'](this.baseUrl + 'auth/logout', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + this.token
                }
            });

            const result = await response.json();
            
            if (result.code === 200) {
                this.token = null;
                localStorage.removeItem('api_token');
                console.log('登出成功:', result);
                return result;
            } else {
                console.error('登出失败:', result);
                throw new Error(result.msg);
            }
        } catch (error) {
            console.error('登出请求失败:', error);
            throw error;
        }
    }

    /**
     * 测试API请求
     */
    async testApiRequest(url, method = 'GET', data = null) {
        if (!this.token) {
            throw new Error('没有可用的token');
        }

        try {
            const options = {
                method: method,
                headers: {
                    'Authorization': 'Bearer ' + this.token
                }
            };

            if (data && method !== 'GET') {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(data);
            }

            const response = await bqAdmin['backend_admin'](this.baseUrl + url, options);
            const result = await response.json();
            
            console.log(`API请求 ${method} ${url}:`, result);
            return result;
        } catch (error) {
            console.error(`API请求失败 ${method} ${url}:`, error);
            throw error;
        }
    }

    /**
     * 设置token
     */
    setToken(token) {
        this.token = token;
        localStorage.setItem('api_token', token);
    }

    /**
     * 获取当前token
     */
    getToken() {
        return this.token;
    }

    /**
     * 清除token
     */
    clearToken() {
        this.token = null;
        localStorage.removeItem('api_token');
    }
}

// 全局实例
window.apiAuthTest = new ApiAuthTest();

// 使用示例：
/*
// 登录
apiAuthTest.login('admin', 'password').then(result => {
    console.log('登录成功:', result);
}).catch(error => {
    console.error('登录失败:', error);
});

// 获取用户信息
apiAuthTest.getCurrentUser().then(result => {
    console.log('用户信息:', result);
}).catch(error => {
    console.error('获取用户信息失败:', error);
});

// 测试其他API
apiAuthTest.testApiRequest('datatable/rest/v1/data-table/model-fields?model=Weline\\Backend\\Model\\BackendUser').then(result => {
    console.log('API测试结果:', result);
}).catch(error => {
    console.error('API测试失败:', error);
});
*/ 