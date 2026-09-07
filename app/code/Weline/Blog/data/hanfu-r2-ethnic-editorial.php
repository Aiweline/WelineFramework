<?php
declare(strict_types=1);

/**
 * Reader-facing ethnic-dress editorial renderer.
 *
 * The source profile supplies the bounded facts. The prose explains how a
 * garment system is worn and understood without turning editorial workflow
 * language into article copy.
 *
 * @param array<string,mixed> $profile
 * @return array{lede:string,sections:list<array{heading:string,paragraphs:list<string>}>,reviewed_facts:list<string>,evidence_keys:list<string>}
 */
function hanfuR2EthnicEditorial(array $profile, string $variant, string $title, string $locale): array
{
    if (!in_array($variant, ['overview', 'occasion'], true)) {
        throw new InvalidArgumentException('Unknown ethnic editorial variant.');
    }

    $en = str_starts_with(strtolower(trim($locale)), 'en');
    $suffix = $en ? '_en' : '';
    $facts = [
        'code' => trim((string)($profile['code'] ?? '')),
        'name' => trim((string)($profile[$en ? 'en' : 'zh'] ?? '')),
        'community' => trim((string)($profile['community' . $suffix] ?? '')),
        'region' => trim((string)($profile['region' . $suffix] ?? '')),
        'silhouette' => trim((string)($profile['silhouette' . $suffix] ?? '')),
        'fabric' => trim((string)($profile['fabric' . $suffix] ?? '')),
        'occasion' => trim((string)($profile['occasion' . $suffix] ?? '')),
        'motif' => trim((string)($profile['motif' . $suffix] ?? '')),
        'limit' => trim((string)($profile['inference_limit' . $suffix] ?? '')),
        'family' => trim((string)($profile['editorial_family'] ?? '')),
    ];
    foreach ($facts as $key => $value) {
        if ($value === '') {
            throw new InvalidArgumentException('Missing ethnic profile field: ' . $key);
        }
    }

    $lede = $en
        ? ($variant === 'overview'
            ? $title . ' introduces ' . $facts['name'] . ' dress through the communities and places in which it is actually worn: ' . $facts['community'] . '. The central clothing system is ' . $facts['silhouette'] . '. Looking at construction, material, movement, and occasion together gives a much clearer picture than treating one colour, headdress, or festival photograph as a complete definition.'
            : $title . ' begins with a practical question: how does ' . $facts['name'] . ' dress work during ' . $facts['occasion'] . '? In ' . $facts['region'] . ', dress can change with locality, season, age, role, and the nature of the event. The aim is therefore not to build a costume formula, but to understand how ' . $facts['silhouette'] . ' is prepared, worn, and cared for in context.')
        : ($variant === 'overview'
            ? '《' . $title . '》从真实的社区与地域讲起：' . $facts['community'] . '。理解' . $facts['name'] . '服饰，重点不是记住某一种颜色、头饰或节庆照片，而是看懂“' . $facts['silhouette'] . '”怎样由衣片、开合、材料和穿着顺序共同构成。把结构、动作与场合放在一起，才能避免把局部特征误当成整套服装。'
            : '《' . $title . '》关心的是一个具体问题：' . $facts['name'] . '服饰在“' . $facts['occasion'] . '”中怎样穿、怎样活动、又该怎样理解。' . $facts['region'] . '内部也会因地方、季节、年龄和参与角色而变化，因此这里不会给出一套僵硬的“民族风造型公式”，而是沿着“' . $facts['silhouette'] . '”的真实穿着关系逐层说明。');

    $sections = [];
    foreach (hanfuR3EthnicFamilyOutline($facts['family'], $variant, $en) as $index => $heading) {
        $sections[] = [
            'heading' => ($index + 1) . '. ' . $heading,
            'paragraphs' => hanfuR3EthnicFamilyParagraphs($facts, $variant, $index, $en),
        ];
    }

    return [
        'lede' => $lede,
        'sections' => $sections,
        'reviewed_facts' => $en ? [
            $facts['name'] . ' community and local context: ' . $facts['community'],
            $facts['name'] . ' geographic scope: ' . $facts['region'],
            $facts['name'] . ' main clothing structure: ' . $facts['silhouette'],
            'Materials and techniques commonly discussed for ' . $facts['name'] . ': ' . $facts['fabric'],
            'Commonly documented occasions for ' . $facts['name'] . ': ' . $facts['occasion'],
        ] : [
            $facts['name'] . '社区与地方语境：' . $facts['community'],
            $facts['name'] . '地域范围：' . $facts['region'],
            $facts['name'] . '主要服装结构：' . $facts['silhouette'],
            $facts['name'] . '常见材料与工艺：' . $facts['fabric'],
            $facts['name'] . '资料中常见的穿着场合：' . $facts['occasion'],
        ],
        'evidence_keys' => is_array($profile['evidence_keys'] ?? null)
            ? array_values($profile['evidence_keys'])
            : [],
    ];
}

/** @return list<string> */
function hanfuR3EthnicFamilyOutline(string $family, string $variant, bool $en): array
{
    $overview = $en ? [
        'robe_system' => ['Recognising the complete robe', 'Opening, sash, and dressing order', 'Sleeves, stride, and body movement', 'Cloth, edging, and repair', 'Motifs without over-reading them', 'Occasion and regional variation', 'How to view and buy responsibly'],
        'upper_lower_ensemble' => ['Reading the outfit as a whole', 'Upper garment, lower garment, and waist', 'Proportion during sitting and walking', 'Textiles and surface decoration', 'Motifs in their proper setting', 'Everyday and formal variation', 'Choosing an informed modern version'],
        'wrap_tube_skirt_system' => ['How the wrap system is formed', 'Overlap, waist control, and coverage', 'What changes when the wearer moves', 'Cloth direction, dye, and ornament', 'Reading motifs with restraint', 'Local forms and occasions', 'Practical and respectful choices'],
        'trousers_jacket_system' => ['Jacket and trousers as one system', 'Closure, rise, cuff, and proportion', 'Clothing made for movement', 'Fibre, trim, and embroidery', 'Where decoration sits on the garment', 'Work, celebration, and local change', 'What to look for in a modern piece'],
        'cape_outerwear_system' => ['Outerwear in the full dressed body', 'Fastening, layering, and weather', 'Weight, movement, and balance', 'Wool, hide, woven cloth, and repair', 'Surface pattern and skilled making', 'Season, locality, and use', 'Buying and wearing with care'],
        'religious_modesty_dress' => ['Dress within community life', 'Coverage, layers, and closure', 'Comfort and participation', 'Material, colour, and attributed meaning', 'Decoration without assumptions', 'Local guidance and personal variation', 'Respectful styling and photography'],
        'craft_object_led' => ['Beginning with the made object', 'Construction before appearance', 'How the textile behaves in use', 'Fibre, weave, dye, and handwork', 'Motifs and maker knowledge', 'Community use and living change', 'Supporting skilled making responsibly'],
    ] : [
        'robe_system' => ['先看完整袍服', '开合、腰带与穿着顺序', '袖部、步幅与身体活动', '面料、缘饰与修补', '怎样理解纹样而不过度解读', '场合与地方差异', '怎样看、怎样买才稳妥'],
        'upper_lower_ensemble' => ['把上衣下装看成一个整体', '衣、裙裤与腰部关系', '坐走之间的比例变化', '织物与表面装饰', '把纹样放回合适语境', '日常与正式穿着的变化', '怎样选择现代版本'],
        'wrap_tube_skirt_system' => ['围裹系统怎样成立', '重叠、腰部固定与覆盖', '动作会改变哪些细节', '布向、染色与装饰', '克制地理解纹样', '地方款式与穿着场合', '实用又尊重的选择'],
        'trousers_jacket_system' => ['短衣与裤装的完整关系', '开合、裤腰、裤脚与比例', '为活动服务的服装', '纤维、边饰与刺绣', '装饰在衣服上的位置', '劳动、节庆与地方变化', '现代成衣应该看什么'],
        'cape_outerwear_system' => ['外衣要放进整套层次里看', '闭合、叠穿与气候', '重量、动作与平衡', '毛、皮、织物与修补', '表面纹样与制作技艺', '季节、地方与用途', '选购与穿着的注意事项'],
        'religious_modesty_dress' => ['服饰与社区生活', '遮覆、层次与闭合', '舒适度与参与方式', '材料、颜色与有出处的含义', '不凭装饰推断身份', '地方指引与个人差异', '尊重的搭配与拍摄'],
        'craft_object_led' => ['从真实制作的物件开始', '先看结构，再看外观', '织物在使用中的表现', '纤维、织法、染色与手工', '纹样与制作者知识', '社区使用与活态变化', '怎样支持真正的技艺'],
    ];

    $occasion = $en ? [
        'robe_system' => ['Start with the wearer’s role', 'Prepare the robe in the right order', 'Check sash and hem in motion', 'Dress for weather and duration', 'Keep accessories in balance', 'Understand formal and local differences', 'A final comfort and respect check'],
        'upper_lower_ensemble' => ['Match the outfit to the event', 'Build the layers from the inside out', 'Set waistline and length for movement', 'Choose cloth for season and duration', 'Add ornament without hiding structure', 'Recognise local and family choices', 'Rehearse the complete outfit'],
        'wrap_tube_skirt_system' => ['Choose the right context first', 'Secure overlap and waist comfortably', 'Test coverage through real movement', 'Use cloth and ornament for the conditions', 'Keep the visual focus clear', 'Follow local ways of wearing', 'Finish with a movement check'],
        'trousers_jacket_system' => ['Let the activity guide the outfit', 'Set jacket, waistband, and cuffs', 'Test the movements the event requires', 'Plan for heat, weather, and long wear', 'Use trim and jewellery selectively', 'Allow for regional and personal choice', 'Check comfort before leaving'],
        'cape_outerwear_system' => ['Begin with season and setting', 'Layer and fasten without strain', 'Manage weight during movement', 'Protect weather-sensitive materials', 'Balance outerwear and ornament', 'Respect locality and participant role', 'Prepare for a full day of wear'],
        'religious_modesty_dress' => ['Ask what participation requires', 'Arrange coverage and closure comfortably', 'Keep movement and privacy in mind', 'Choose suitable materials and colour', 'Treat symbols and jewellery carefully', 'Follow community and venue guidance', 'Photograph and share with respect'],
        'craft_object_led' => ['Understand the object before styling', 'Prepare attachment and supporting layers', 'Test flex, wear, and movement', 'Protect the material during the event', 'Let workmanship remain visible', 'Credit local practice and the maker', 'Care for the piece after wearing'],
    ] : [
        'robe_system' => ['先看当天的参与角色', '按正确顺序穿好袍服', '活动中检查腰带与下摆', '根据天气和时长调整', '让配饰保持平衡', '理解正式程度与地方差异', '最后检查舒适与尊重'],
        'upper_lower_ensemble' => ['让整套衣服匹配场合', '从内到外安排层次', '按动作调整腰线与长度', '根据季节和时长选面料', '加装饰但不遮住结构', '尊重地方与家庭选择', '出门前完整排练一次'],
        'wrap_tube_skirt_system' => ['先选对使用语境', '舒适地固定重叠与腰部', '用真实动作测试覆盖', '让面料装饰适应环境', '把视觉重点留清楚', '遵循地方穿着方法', '用动作检查完成造型'],
        'trousers_jacket_system' => ['让活动方式决定穿法', '调好短衣、裤腰与裤脚', '测试场合真正需要的动作', '应对温度天气与久穿', '有选择地使用边饰首饰', '给地方和个人选择留空间', '出发前确认全天舒适'],
        'cape_outerwear_system' => ['从季节与环境开始', '叠穿与闭合不要造成拉扯', '活动中管理重量', '保护对天气敏感的材料', '平衡外衣与饰物', '尊重地方与参与角色', '为长时间穿着做准备'],
        'religious_modesty_dress' => ['先了解参与场合的要求', '舒适安排遮覆与闭合', '同时照顾动作与隐私', '选择适合的材料和颜色', '谨慎处理符号与首饰', '遵循社区和场地指引', '尊重地拍摄与分享'],
        'craft_object_led' => ['搭配前先了解实物', '准备连接方式与支撑层', '测试弯折、磨耗与动作', '活动中保护材料', '让真正的手艺被看见', '说明地方实践与制作者', '穿用之后妥善护理'],
    ];

    $outlines = $variant === 'occasion' ? $occasion : $overview;
    return $outlines[$family] ?? $outlines['upper_lower_ensemble'];
}

/**
 * @param array<string,string> $f
 * @return array{construction:string,movement:string,material:string,context:string}
 */
function hanfuR4EthnicFamilyNotes(array $f, bool $en): array
{
    $notes = $en ? [
        'robe_system' => [
            'construction' => 'A robe is read through body panels, opening direction, collar, side or centre fastening, sash, and the layers beneath it.',
            'movement' => 'Sleeve reach, stride, sitting, riding, and the way a sash carries weight reveal more than a straight-on portrait.',
            'material' => 'Large uninterrupted cloth areas make fibre, lining, edging, seam strength, and repair especially important.',
            'context' => 'Robe length and decoration may shift between everyday wear, weather protection, ceremony, performance, and revival dress.',
        ],
        'upper_lower_ensemble' => [
            'construction' => 'An upper-and-lower ensemble depends on the relation between top, skirt or trousers, waist fastening, overlap, and the layer visible beneath.',
            'movement' => 'Waist position, hem length, sleeve room, and the balance of separate pieces should be judged while sitting, walking, and raising the arms.',
            'material' => 'Because top and lower garment may use different cloth, fibre, weave, lining, and ornament should be considered piece by piece.',
            'context' => 'Changing one layer can alter formality, season, and local character without turning the outfit into a different community’s dress.',
        ],
        'wrap_tube_skirt_system' => [
            'construction' => 'A wrap or tube-skirt system is shaped by cloth width, wrap direction, overlap, waist support, and the upper layer worn with it.',
            'movement' => 'Coverage must remain secure through walking, sitting, climbing steps, dancing, and the longest movement expected that day.',
            'material' => 'Cloth direction, border placement, resistance to stretching, and the behaviour of dyed or woven surfaces affect both fit and appearance.',
            'context' => 'A similar rectangular silhouette can be wrapped differently across places, generations, and events, so the local term matters.',
        ],
        'trousers_jacket_system' => [
            'construction' => 'Jacket length, opening, trouser rise, leg volume, cuffs, and the meeting point at the waist form one practical system.',
            'movement' => 'Bending, working, travelling, dancing, and sitting show whether the jacket pulls, the waistband slips, or the cuffs obstruct the wearer.',
            'material' => 'Hard-wearing cloth, reinforced edges, embroidery, braid, and replaceable parts may each answer a different practical need.',
            'context' => 'Workwear, festive dress, stage versions, and contemporary tailoring can share features while serving different lives.',
        ],
        'cape_outerwear_system' => [
            'construction' => 'Outerwear must be read with the clothing beneath it: neckline, fastening points, shoulder load, side coverage, and supporting layers work together.',
            'movement' => 'Weight distribution, wind, rain, riding, walking, and long wear determine whether the outer layer remains stable and comfortable.',
            'material' => 'Wool, felt, hide, fur, woven cloth, lining, and repair patches respond differently to moisture, pressure, heat, and storage.',
            'context' => 'Climate, herd or household resources, trade, ceremony, and modern adaptation can all change an outer garment’s form.',
        ],
        'religious_modesty_dress' => [
            'construction' => 'Coverage, neckline, sleeve and hem length, opacity, closure, and head covering should be understood within local community practice.',
            'movement' => 'The wearer needs to sit, walk, greet, work, and participate without constant readjustment or loss of privacy.',
            'material' => 'Opacity, breathability, drape, colour, fibre, and fastening comfort matter more than a generic visual idea of modesty.',
            'context' => 'Personal observance, family custom, locality, venue, generation, and the nature of the gathering can lead to different choices.',
        ],
        'craft_object_led' => [
            'construction' => 'The best starting point is the made object itself: fibre preparation, weave or felt structure, dye, seams, applied ornament, and method of attachment.',
            'movement' => 'Flex, abrasion, weight, sound, and the point where an object meets the body explain how it performs in wear.',
            'material' => 'Technique should be credited through the maker and process rather than guessed from a decorative surface.',
            'context' => 'An heirloom, a newly commissioned piece, a revival object, and a tourist imitation may look related while carrying different histories.',
        ],
    ] : [
        'robe_system' => [
            'construction' => '袍服要从衣身裁片、开合方向、领部、侧边或中部闭合、腰带以及内层的关系来理解。',
            'movement' => '抬袖、迈步、落座、骑乘和腰带承重，比一张正面站姿照更能说明袍服是否真正成立。',
            'material' => '袍身连续面积较大，纤维、里料、缘边、接缝强度和修补方式都会直接影响穿着寿命。',
            'context' => '袍长与装饰可能随着日常、御寒、礼仪、表演或复兴穿着而变化，不能只凭轮廓归类。',
        ],
        'upper_lower_ensemble' => [
            'construction' => '上衣下装组合取决于衣、裙或裤、腰部系结、衣襟重叠和内层露出之间的关系。',
            'movement' => '腰位、下摆、袖量与分体衣物的平衡，要在坐、走、抬手时判断，不能只看站立效果。',
            'material' => '上衣与下装可能使用不同织物，纤维、织法、里料和装饰应逐件说明，不能用一个面料名带过整套。',
            'context' => '更换一层衣物会改变季节和正式程度，却不意味着可以随意改成另一个社区的服饰名称。',
        ],
        'wrap_tube_skirt_system' => [
            'construction' => '围裹或筒裙系统由布幅、围裹方向、重叠量、腰部支撑以及与之相配的上层衣物共同决定。',
            'movement' => '走路、坐下、登阶或歌舞时都要保持覆盖与稳定，最长时间的动作比镜前站姿更有参考价值。',
            'material' => '布向、边纹位置、抗拉伸能力以及染织表面的变化，会同时影响合身和视觉重心。',
            'context' => '相似的矩形轮廓在不同地区、世代和场合可能有不同围法，因此地方名称和穿法同样重要。',
        ],
        'trousers_jacket_system' => [
            'construction' => '短衣长度与开合、裤腰高度、裤腿余量、裤脚以及腰部衔接，合在一起才是一套实用结构。',
            'movement' => '弯腰、劳动、远行、歌舞和落座能够暴露衣身拉扯、腰头下滑或裤脚妨碍等问题。',
            'material' => '耐磨布、加固边缘、刺绣、织带和可替换部件可能各有用途，不能把装饰与结构混为一谈。',
            'context' => '劳动装、节庆装、舞台版本和当代剪裁可以共享局部特征，但服务的是不同生活场景。',
        ],
        'cape_outerwear_system' => [
            'construction' => '外衣必须连同内层一起看：领口、闭合点、肩部承重、侧面覆盖与支撑层彼此配合。',
            'movement' => '重量分布以及风雨、骑乘、行走和久穿，会决定外层是否稳定、保暖又不妨碍动作。',
            'material' => '羊毛、毡、皮、毛皮、织物、里料和修补片对水分、压力、热和收纳的反应各不相同。',
            'context' => '气候、家庭资源、贸易、礼仪和现代改良都可能改变外衣，不能把某一版本冻结成唯一标准。',
        ],
        'religious_modesty_dress' => [
            'construction' => '遮覆范围、领口、袖长与衣长、面料透明度、闭合和头部服饰，都应放在地方社区实践中理解。',
            'movement' => '穿着者需要能够坐、走、问候、劳动和参与活动，同时减少反复整理与隐私压力。',
            'material' => '不透、透气、垂坠、颜色、纤维与系结舒适度，比笼统的“端庄感”更能指导真实选择。',
            'context' => '个人实践、家庭习惯、地方、场地、世代和活动性质不同，都会带来合理的穿着差异。',
        ],
        'craft_object_led' => [
            'construction' => '最好的起点是真实物件：纤维处理、织造或毡化结构、染色、接缝、附加装饰与连接方法。',
            'movement' => '弯折、摩擦、重量、声音以及物件与身体接触的位置，可以解释它在穿用中怎样工作。',
            'material' => '工艺应通过制作者和具体过程来说明，不能只看表面效果就替一件作品猜制作方法。',
            'context' => '传家物、新定制、复兴作品与旅游仿制品可能外观相关，却拥有完全不同的经历与价值。',
        ],
    ];

    return $notes[$f['family']] ?? $notes['upper_lower_ensemble'];
}

/** @param array<string,string> $f @return list<string> */
function hanfuR3EthnicFamilyParagraphs(array $f, string $variant, int $index, bool $en): array
{
    if ($index < 0 || $index > 6) {
        throw new OutOfBoundsException('Unknown ethnic article section.');
    }
    $notes = hanfuR4EthnicFamilyNotes($f, $en);

    if ($variant === 'overview') {
        $paragraphs = $en ? [
            $f['name'] . ' dress cannot be reduced to a single emblematic outfit. Within ' . $f['community'] . ', the useful starting point is the documented relationship described as ' . $f['silhouette'] . '. Read the whole dressed body before isolating a collar, skirt, robe, headdress, or decorative border; otherwise a striking detail can easily be mistaken for the clothing system itself.',
            'Construction explains why the pieces stay in place. For ' . $f['name'] . ' clothing associated with ' . $f['region'] . ', look for opening direction, overlap, ties, waist support, underlayers, and the order in which pieces meet. These relationships are more dependable than colour when distinguishing a complete outfit from a stage adaptation or a modern item that borrows only its surface style.',
            'A garment should make sense on a moving person, not only on a mannequin. When considering ' . $f['silhouette'] . ', imagine the wearer sitting, walking, climbing steps, reaching, and remaining dressed for the length of ' . $f['occasion'] . '. Proportion, coverage, load, and fastening should continue to work through those movements without forcing the wearer to hold the outfit in place.',
            'The material vocabulary associated here with ' . $f['name'] . ' is ' . $f['fabric'] . '. Fibre is only the first layer of that description: weave, thickness, lining, dye, embroidery, applied pieces, metal, beads, and repair all change how the garment hangs and ages. A careful reader therefore asks which material belongs to which piece instead of applying one luxurious label to the entire look.',
            'The motif range noted for this subject is ' . $f['motif'] . '. Pattern placement, scale, technique, and the maker’s own explanation matter as much as the motif name. A flower, geometric border, or animal form may be decorative, local, personal, commercial, or ceremonial; appearance alone does not reveal a fixed message, rank, marital status, or belief.',
            $f['occasion'] . ' is a useful guide to use, but it is not a uniform. In ' . $f['region'] . ', everyday clothing, festive clothing, performance costume, revival work, and contemporary fashion may coexist. Age, locality, weather, household, occupation, and personal preference can all produce valid differences within ' . $f['community'] . '.',
            'For a museum visit, purchase, display, or styling project, use the most specific local garment name available and check whether the piece is community-made, commissioned, reconstructed, adapted, or made as costume. ' . $f['limit'] . ' This boundary protects the living community while also giving the reader a more accurate basis for comparison and care.',
        ] : [
            $f['name'] . '服饰不能被压缩成一套固定“代表服”。在' . $f['community'] . '中，比较可靠的起点是“' . $f['silhouette'] . '”所描述的完整穿着关系。应先看全身结构，再单独讨论领、裙、袍、头饰或边纹；否则一个醒目的局部，很容易被误当成整套服装的定义。',
            '结构能够解释各件衣物为什么稳固。观察' . $f['region'] . '相关的' . $f['name'] . '服饰时，要看开合方向、衣襟重叠、系带、腰部支撑、内层以及各件相接的顺序。这些关系比颜色更能区分完整服装、舞台改造和只借用表面风格的现代商品。',
            '服装要穿在活动的人身上才真正成立。面对“' . $f['silhouette'] . '”，不妨把落座、行走、登阶、抬手以及“' . $f['occasion'] . '”所需的持续时间都考虑进去。比例、覆盖、重量和闭合应在动作中继续工作，而不是要求穿着者一直用手扶住。',
            $f['name'] . '服饰在这里涉及的材料是“' . $f['fabric'] . '”。纤维只是第一层信息，织法、厚薄、里料、染色、刺绣、附加部件、金属、珠饰和修补都会改变垂坠与寿命。判断时应说明哪一种材料属于哪一件衣物，不能用一个华丽的材料名概括整套。',
            '与这一主题相关的纹样范围是“' . $f['motif'] . '”。纹样出现的位置、尺度、制作方法以及制作者自己的解释，与名称同样重要。花卉、几何边纹或动物形象可能来自地方习惯、个人选择、市场设计或特定场合，不能仅凭外观推断固定寓意、等级、婚姻或信仰。',
            '“' . $f['occasion'] . '”可以帮助理解用途，却不是人人相同的制服。在' . $f['region'] . '，日常衣着、节庆盛装、表演服、复兴作品与当代时装可以同时存在；年龄、地方、天气、家庭、生计和个人偏好，也会在' . $f['community'] . '内部形成合理差异。',
            '无论用于参观、选购、陈列还是搭配，都应尽量使用具体的地方服装名称，并分清对象属于社区制作、委托定制、复原、改良还是表演服。' . $f['limit'] . '守住这条边界，既是对活态社区的尊重，也能让比较、护理和购买更准确。',
        ];
    } else {
        $paragraphs = $en ? [
            'Before dressing for ' . $f['occasion'] . ', establish the wearer’s actual role: participant, host, performer, visitor, member of the community, or someone wearing a clearly labelled modern adaptation. For ' . $f['name'] . ' dress in ' . $f['region'] . ', that role affects formality, permitted movement, photography, and whether local guidance is needed. The event name by itself does not determine one correct outfit.',
            'Build ' . $f['silhouette'] . ' in the order that lets each layer support the next. Check underlayers, opening, ties or fasteners, waist position, footwear, and any piece that needs another person’s help. A stable base matters more than adding every visible ornament; if the foundation twists or slips, decoration will make the problem heavier rather than more authentic.',
            'Rehearse the actions that will actually happen during ' . $f['occasion'] . ': sitting, greeting, walking outdoors, climbing, dancing, travelling, serving, or remaining still for a long period. With ' . $f['name'] . ' clothing, watch the hem, sleeves, waist, headwear, and attached objects. Nothing should block sight, catch on the ground, or require constant correction.',
            'Plan for the temperature, weather, and duration in ' . $f['region'] . '. The relevant material range—' . $f['fabric'] . '—may contain components that react differently to heat, moisture, rubbing, pressure, or colour transfer. Follow the maker’s care instructions for the most delicate component, provide a breathable underlayer where appropriate, and never assume that one community label supplies a universal wash rule.',
            'Accessories should support the clothing system rather than cover it. When working with ' . $f['motif'] . ', choose one clear visual focus and keep attachment points secure. Avoid inventing a ritual explanation for a pattern, mixing sacred or status-bearing objects into a fashion look, or pairing a recognisable headdress with an unrelated base garment simply because the colours match.',
            'Local etiquette and personal choice still matter within ' . $f['community'] . '. Ask before photographing, touching, borrowing, or reproducing a distinctive object, and describe the exact locality and event when sharing images. ' . $f['limit'] . ' A respectful caption says what is known without assigning a participant an identity or ceremonial role from appearance alone.',
            'Complete the outfit early enough to wear it for at least an hour before the event. Recheck fastening, movement, footwear, weather, transport, privacy, and a safe way to store removed layers. If the piece is a contemporary interpretation, label it that way; appreciation does not require presenting a new commercial garment as an old community original.',
        ] : [
            '为“' . $f['occasion'] . '”准备衣着之前，先弄清当天的真实角色：社区成员、参与者、主办者、表演者、访客，还是穿着明确标注的现代改良款。对' . $f['region'] . '的' . $f['name'] . '服饰而言，角色会影响正式程度、活动方式、拍摄边界以及是否需要地方指引；只有一个活动名称，并不能决定唯一正确的穿法。',
            '“' . $f['silhouette'] . '”要按能够相互支撑的顺序穿好。内层、开合、系带或扣件、腰位、鞋履，以及需要他人协助的部件都应提前确认。稳定的基础比把所有饰物一次加满更重要；若底层已经扭转下滑，装饰只会让问题更重，并不会让造型更准确。',
            '把“' . $f['occasion'] . '”中真正会发生的动作预演一遍，例如落座、问候、户外行走、登阶、歌舞、乘车、端送或长时间站立。穿着' . $f['name'] . '服饰时，要观察下摆、袖部、腰部、头饰和附着物，避免遮挡视线、拖地勾挂或需要不停整理。',
            '还要根据' . $f['region'] . '的温度、天气和活动时长安排材料。“' . $f['fabric'] . '”可能包含对热、水分、摩擦、压力或移色反应不同的部件；护理应服从整件衣服里最脆弱的部分，必要时安排透气内层，不能根据民族名称猜一种通用洗法。',
            '配饰应服务服装结构，而不是把结构全部盖住。使用“' . $f['motif'] . '”时，整套保留一个清楚重点，并确认连接处安全。不要替纹样编造礼仪解释，也不要把可能涉及宗教或身份的物件当成普通时尚配件，更不能因为颜色接近就把醒目头饰配到无关衣身上。',
            $f['community'] . '内部仍然存在地方礼俗与个人选择。拍摄、触碰、借用或复制有辨识度的物件前应先征得同意，分享图片时说明具体地点和活动。' . $f['limit'] . '尊重的说明只写能够确认的内容，不从外观替参与者指认身份或礼仪角色。',
            '整套衣服最好提前完成，并在活动前连续试穿至少一小时，再检查闭合、动作、鞋履、天气、交通、隐私以及脱下外层后的安全收纳。若购买的是当代演绎，就坦率使用改良或灵感款名称；欣赏一种传统，并不需要把新的商业成衣说成古老的社区原作。',
        ];
    }

    $anchors = $en ? [
        ' This keeps the complete ' . $f['name'] . ' clothing system in view.',
        ' For ' . $f['name'] . ' dress, a stable sequence is always more useful than last-minute ornament.',
        ' Movement is therefore part of understanding ' . $f['name'] . ' clothing, not an afterthought.',
        ' Material-specific care is essential to the long life of ' . $f['name'] . ' dress.',
        ' This restraint lets the workmanship of ' . $f['name'] . ' dress remain visible.',
        ' Local voices remain central to any account of ' . $f['name'] . ' clothing.',
        ' That final distinction supports informed and repeatable wear of ' . $f['name'] . ' dress.',
    ] : [
        '这样才能始终看见' . $f['name'] . '服饰的完整关系。',
        '对于' . $f['name'] . '服饰，稳定的穿着顺序始终比临时加饰更重要。',
        '所以，动作本来就是理解' . $f['name'] . '服饰的一部分，而不是造型完成后的附加题。',
        '按照真实材料护理，是延长' . $f['name'] . '服饰寿命的必要条件。',
        '这种克制能让' . $f['name'] . '服饰真正的制作特点保持清楚。',
        '讲述' . $f['name'] . '服饰时，地方使用者的声音始终应放在中心。',
        '分清这些关系，才能让' . $f['name'] . '服饰被准确而长久地穿用。',
    ];
    $primary = $paragraphs[$index];
    if (!str_contains($primary, $f['name'])) {
        $primary .= $anchors[$index];
    }
    $detail = hanfuR3EthnicFamilyDetail($f, $variant, $index, $en, $notes);
    if (!str_contains($detail, $f['name'])) {
        $detail .= $anchors[$index];
    }

    return [$primary, $detail];
}

/**
 * @param array<string,string> $f
 * @param array{construction:string,movement:string,material:string,context:string}|null $notes
 */
function hanfuR3EthnicFamilyDetail(
    array $f,
    string $variant,
    int $index,
    bool $en,
    ?array $notes = null,
): string {
    $notes ??= hanfuR4EthnicFamilyNotes($f, $en);

    if ($variant === 'overview') {
        $details = $en ? [
            'For ' . $f['name'] . ', this structural view begins with a simple discipline: identify every visible piece and the point where it meets the next. ' . $notes['construction'] . ' Only after that relationship is clear should colour, age, or regional resemblance influence the description.',
            'The dressing sequence also helps a buyer or museum visitor spot missing components. In the case of ' . $f['silhouette'] . ', a cropped photograph may hide the lower layer, back fastening, or support at the waist. Multiple views and a specific local name are therefore more useful than a dramatic front portrait.',
            $notes['movement'] . ' This is especially relevant to ' . $f['name'] . ' because the documented use includes ' . $f['occasion'] . '. A modern version can adjust ease or length for daily life, but those changes should be described instead of being concealed under a timeless traditional label.',
            $notes['material'] . ' For the materials named in this profile—' . $f['fabric'] . '—look at joins, reverse sides, abrasion points, and later repairs. These details often reveal skilled decisions that a distant photograph or a generic product description leaves invisible.',
            'With ' . $f['motif'] . ', the safest and most interesting questions are concrete: where is it placed, how was it made, who named it, and whether the explanation comes from a maker, collection, or local publication. That approach gives ' . $f['name'] . ' ornament depth without turning every repeated shape into a universal symbol.',
            $notes['context'] . ' In ' . $f['region'] . ', comparisons should therefore stay close in place, date, wearer, and occasion. Two different outfits may both be appropriate, while two visually similar outfits may belong to very different contexts.',
            'A responsible contemporary choice keeps ' . $f['community'] . ' visible in the description, credits a known maker where possible, and avoids relabelling the dress as Hanfu or a generic ethnic costume. Good attribution does not make the clothing less wearable; it gives the wearer a better story to tell.',
        ] : [
            '理解' . $f['name'] . '结构，可以先做一件简单的事：认清画面里每一件衣物，以及它与下一层在哪里相接。' . $notes['construction'] . '只有这些关系清楚以后，颜色、年代感和地域相似性才适合进入判断。',
            '穿着顺序也能帮助购买者或参观者发现缺件。以“' . $f['silhouette'] . '”为例，裁切过的照片可能藏住下层、背面闭合或腰部支撑；因此，多角度图片和具体地方名称，远比一张戏剧化正面肖像更有用。',
            $notes['movement'] . '这一点对' . $f['name'] . '尤其重要，因为相关用途包括“' . $f['occasion'] . '”。现代版本可以为日常活动调整松量或长度，但应把改动说清楚，而不是藏进一个仿佛从未变化的“传统款”标签里。',
            $notes['material'] . '对于这里提到的“' . $f['fabric'] . '”，还应观察连接处、背面、磨损点和后来的修补。正是这些细节，常常显示出远景照片或笼统商品说明看不见的制作判断。',
            '面对“' . $f['motif'] . '”，既稳妥又有意思的问题是：它出现在什么位置、用什么方法制成、由谁命名，解释来自制作者、收藏机构还是地方资料。这样既能讲出' . $f['name'] . '装饰的深度，也不会把每个重复形状都说成统一象征。',
            $notes['context'] . '所以，在' . $f['region'] . '进行比较时，应尽量靠近相同地点、年代、穿着者和场合。两套不同衣服可能都很合适，两套外观相似的衣服也可能属于完全不同的语境。',
            '负责任的当代选择，会在说明中保留' . $f['community'] . '，尽可能标明已知制作者，并避免把服饰改称汉服或笼统“民族风戏服”。准确出处不会让衣服变得难穿，反而能给穿着者一个更真实、更值得分享的故事。',
        ];
    } else {
        $details = $en ? [
            $notes['context'] . ' For ' . $f['name'] . ' at ' . $f['occasion'] . ', ask a local organiser or knowledgeable participant when the event carries rules that a visitor may not see. Listening first prevents a styling choice from creating inconvenience or disrespect.',
            $notes['construction'] . ' During preparation, take front, side, and back views for personal adjustment, then check that no fastening bears all the load. The goal is a calm, secure silhouette that still lets the wearer breathe and use the space comfortably.',
            $notes['movement'] . ' Repeat the most demanding action several times while wearing the planned shoes and underlayers. If ' . $f['silhouette'] . ' loosens, twists, or catches, correct the support or simplify the outfit before relying on decorative pins.',
            $notes['material'] . ' With ' . $f['fabric'] . ', pack only suitable repair and weather protection: a clean cloth, spare non-damaging fastening, breathable cover, or maker-recommended care item. Emergency adhesive, heat, or aggressive stain treatment can permanently damage a valued piece.',
            'For ' . $f['name'] . ' styling, let ' . $f['motif'] . ' remain connected to the garment or maker who supplied it. Modern jewellery and bags can be quiet companions, but imitation insignia or an unrelated ceremonial object changes the cultural claim of the whole look.',
            'In photographs from ' . $f['region'] . ', keep the setting and participant role in the caption when they are known, and do not recycle one person or garment as proof of several local forms. ' . $f['limit'] . ' That restraint leaves room for people to describe themselves.',
            'After ' . $f['occasion'] . ', air and store each component according to its actual material, remove pressure from heavy ornament, and note any loose seam before the next wear. Care is part of respectful use: it values the labour in the piece and makes repeat wearing possible.',
        ] : [
            $notes['context'] . '在“' . $f['occasion'] . '”中穿着' . $f['name'] . '服饰，若活动存在访客看不见的规范，应先向当地组织者或熟悉情况的参与者请教。先听再搭配，可以避免一个看似漂亮的选择给自己或他人造成不便。',
            $notes['construction'] . '准备时可以拍下正、侧、背三面供自己调整，再确认没有某一个系结点承担全部重量。理想状态是轮廓安稳、呼吸顺畅，穿着者也能自然使用当天的空间。',
            $notes['movement'] . '穿上计划中的鞋履和内层，把最费力的动作重复几次。若“' . $f['silhouette'] . '”出现松脱、扭转或勾挂，应先修正支撑或简化层次，不能指望用更多装饰别针掩盖问题。',
            $notes['material'] . '针对“' . $f['fabric'] . '”，随身只准备真正适用的修补和防护，例如干净软布、不会损伤织物的备用系结、透气罩袋或制作者建议的用品；临时使用胶、热处理或强力去渍，可能给珍贵部件留下永久损伤。',
            '搭配' . $f['name'] . '服饰时，让“' . $f['motif'] . '”继续与提供它的衣物或制作者相连。现代首饰和包袋可以克制呼应，但仿制身份标识或无关礼仪物件会改变整套造型所表达的文化关系。',
            '分享' . $f['region'] . '相关照片时，已知的地点、活动和参与角色应留在说明中，也不要把同一人物或同一服装裁切成多个地方版本。' . $f['limit'] . '这种克制不是减少信息，而是把自我说明的空间留给真实的人。',
            '“' . $f['occasion'] . '”结束后，应按每个部件的真实材料通风收纳，解除重饰对底布的长期压力，并在下次穿着前处理松线。护理也是尊重的一部分：它珍惜衣物中的劳动，也让一件服装能够真正被反复使用。',
        ];
    }

    return $details[$index];
}
