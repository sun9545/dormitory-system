#!/usr/bin/env python3
# 修复字体文件中的static_bitmap字段问题

import re

def fix_font_file():
    try:
        # 读取原文件
        with open('myFont_new.c', 'r', encoding='utf-8') as f:
            content = f.read()
        
        print("原文件大小:", len(content), "字符")
        
        # 删除 .static_bitmap = 0, 这一行
        content = re.sub(r'    \.static_bitmap = 0,\n', '', content)
        
        # 写入修复后的文件
        with open('myFont_new_fixed.c', 'w', encoding='utf-8') as f:
            f.write(content)
        
        print("✅ 修复完成！已生成 myFont_new_fixed.c")
        print("修复后文件大小:", len(content), "字符")
        
    except Exception as e:
        print(f"❌ 修复失败: {e}")

if __name__ == "__main__":
    fix_font_file()