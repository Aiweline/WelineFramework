提供钩子功能
使用方法：
在任意模块中view/hooks目录下新建文件，
文件名为需要的钩子名(区分大小写)。

例如:

view/hooks/head.phtml

表明要处理head钩子，
并在head.phtml文件中写入自定义代码即可。

自定义代码将在head钩子中执行。
你可以用此方法向具有相同钩子的模板位置<hook>head</hook>添加代码。