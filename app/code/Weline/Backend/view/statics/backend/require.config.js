requirejs.config({
    //要在IE中及时获得正确的错误触发器，请强制进行定义/填充导出检查。
    enforceDefine: true,
    paths: {
        jquery: [
            // CDN 加载jquery 如果是全球站点可考虑配置
            "https://ajax.googleapis.com/ajax/libs/jquery/1.4.4/jquery.min",
            "https://code.bdstatic.com/npm/jquery@3.5.1/dist/jquery.min",
            //如果CDN位置失败，请从此位置加载jquery
            'Weline_Backend/lib/jquery/3.6.0/jquery.min'
        ],
        vue: [
            'Weline_Backend/lib/vue/vue2.6.11.js'
        ]
    }
});